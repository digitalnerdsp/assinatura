<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SigningService;
use App\Jobs\DeliverWebhook;
use App\Mail\SignatureRequestedMail;
use App\Messaging\MessageSender;
use App\Messaging\Phone;
use App\Models\DocumentTemplate;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\SignerNotifier;
use App\Signing\ReportBuilder;
use App\Support\SystemHealth;
use App\Templates\TemplateRenderer;
use App\Webhooks\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function growthPdf(): \Illuminate\Http\UploadedFile
{
    return \Illuminate\Http\UploadedFile::fake()->createWithContent('contrato.pdf', "%PDF-1.4
1 0 obj<<>>endobj
trailer<<>>
%%EOF
");
}

function growthRegister(string $email = 'dono@acme.com', string $company = 'ACME'): User
{
    test()->post('/cadastro', [
        'company' => $company, 'name' => 'Dono', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

function growthWebEnvelope(): Envelope
{
    test()->post('/envelopes', [
        'title' => 'Contrato de serviço', 'document' => growthPdf(), 'signing_order' => 'parallel',
        'signers' => [['name' => 'Ana', 'email' => 'ana@ex.com'], ['name' => 'Beto', 'email' => 'beto@ex.com']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    return Envelope::latest()->firstOrFail();
}

final class GrowthFakeSender implements MessageSender
{
    /** @var list<array{string,string,string}> */
    public static array $sent = [];

    public function send(string $channel, string $to, string $text): void
    {
        self::$sent[] = [$channel, $to, $text];
    }
}

/** Cria um envelope enviado de verdade (com tokens), para o tenant dado. */
function growthEnvelope(Tenant $tenant, SigningOrder $order = SigningOrder::Parallel, int $signers = 2, array $options = []): Envelope
{
    Storage::fake('local');
    $envelope = app(EnvelopeService::class)->create(
        $tenant, null, "%PDF-1.4\n%t\n", 'c.pdf', 'Contrato',
        array_map(fn ($i) => ['name' => "Signer {$i}", 'email' => "s{$i}@example.com", 'phone' => '(11) 99999-000'.$i], range(1, $signers)),
        $order, 'simple', null, [], $options,
    );
    app(EnvelopeService::class)->send($envelope);

    return $envelope->fresh();
}

// ---------------------------------------------------------------- ordem de assinatura

it('em ordem sequencial só avisa o primeiro e avisa o próximo quando o anterior assina', function () {
    Mail::fake();
    Queue::fake();
    $envelope = growthEnvelope(Tenant::create(['name' => 'ACME']), SigningOrder::Sequential, 3);

    $failed = app(SignerNotifier::class)->inviteActive($envelope);
    expect($failed)->toBe([]);
    Mail::assertSent(SignatureRequestedMail::class, 1);
    Mail::assertSent(SignatureRequestedMail::class, fn ($m) => $m->hasTo('s1@example.com'));

    // chamar de novo não duplica o convite
    app(SignerNotifier::class)->inviteActive($envelope);
    Mail::assertSent(SignatureRequestedMail::class, 1);

    app(SigningService::class)->sign($envelope->signers()->where('position', 1)->first(), 'typed', 'Signer 1', true);
    Mail::assertSent(SignatureRequestedMail::class, 2);
    Mail::assertSent(SignatureRequestedMail::class, fn ($m) => $m->hasTo('s2@example.com'));
    Mail::assertNotSent(SignatureRequestedMail::class, fn ($m) => $m->hasTo('s3@example.com'));
});

it('a página de assinatura avisa quem ainda não está na vez', function () {
    $envelope = growthEnvelope(Tenant::create(['name' => 'ACME']), SigningOrder::Sequential);
    $second = $envelope->signers()->where('position', 2)->first();

    $this->get($second->signUrl())->assertOk()->assertSee('Você será avisado quando for a sua vez');
    $this->get($envelope->signers()->where('position', 1)->first()->signUrl())->assertOk()->assertDontSee('Você será avisado quando for a sua vez');
});

// ---------------------------------------------------------------- lembretes e expiração

it('envia lembrete só depois do intervalo e respeita o limite', function () {
    Mail::fake();
    $envelope = growthEnvelope(Tenant::create(['name' => 'ACME']), options: ['reminder_every_days' => 2]);
    $envelope->signers()->update(['invited_at' => now()->subDays(1)]);

    $this->artisan('envelopes:remind')->expectsOutputToContain('0 lembrete');
    Mail::assertNothingSent();

    $envelope->signers()->update(['invited_at' => now()->subDays(3)]);
    $this->artisan('envelopes:remind')->expectsOutputToContain('2 lembrete');
    Mail::assertSent(SignatureRequestedMail::class, 2);
    Mail::assertSent(SignatureRequestedMail::class, fn ($m) => $m->reminder === true);

    $this->artisan('envelopes:remind')->expectsOutputToContain('0 lembrete'); // acabou de lembrar
    expect($envelope->signers()->first()->reminders_count)->toBe(1);
    expect($envelope->evidenceEvents()->where('type', 'reminder_sent')->count())->toBe(2);
});

it('não lembra quando o lembrete está desligado nem quem já assinou', function () {
    Mail::fake();
    Queue::fake();
    $off = growthEnvelope(Tenant::create(['name' => 'A']), options: ['reminder_every_days' => null]);
    $off->signers()->update(['invited_at' => now()->subDays(10)]);

    $on = growthEnvelope(Tenant::create(['name' => 'B']), options: ['reminder_every_days' => 1]);
    $on->signers()->update(['invited_at' => now()->subDays(10)]);
    app(SigningService::class)->sign($on->signers()->where('position', 1)->first(), 'typed', 'x', true);

    $this->artisan('envelopes:remind')->expectsOutputToContain('1 lembrete'); // só o signatário 2 do envelope B
    Mail::assertSent(SignatureRequestedMail::class, 1);
    Mail::assertSent(SignatureRequestedMail::class, fn ($m) => $m->hasTo('s2@example.com'));
});

it('expira envelopes vencidos e registra na trilha', function () {
    Queue::fake();
    $envelope = growthEnvelope(Tenant::create(['name' => 'ACME']));
    $envelope->update(['expires_at' => now()->subMinute()]);

    $this->artisan('envelopes:expire')->expectsOutputToContain('1 envelope');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Expired);
    expect($envelope->evidenceEvents()->where('type', 'envelope_expired')->exists())->toBeTrue();
    expect(fn () => app(SigningService::class)->findByToken(basename($envelope->signers()->first()->signUrl())))
        ->toThrow(FlowException::class);
});

it('o prazo do envelope vem de validity_days', function () {
    $envelope = growthEnvelope(Tenant::create(['name' => 'ACME']), options: ['validity_days' => 7]);

    expect((int) round(now()->diffInDays($envelope->expires_at, true)))->toBe(7);
});

// ---------------------------------------------------------------- webhooks

it('entrega webhooks assinados com HMAC quando o signatário assina', function () {
    config(['webhooks.allow_private' => true]);
    Http::fake(['*' => Http::response('ok', 200)]);
    Queue::fake([\App\Jobs\SealEnvelope::class]);

    $tenant = Tenant::create(['name' => 'ACME']);
    $endpoint = WebhookEndpoint::create(['tenant_id' => $tenant->id, 'url' => 'http://hooks.test/in', 'secret' => 'whsec_abc', 'events' => ['signer.signed'], 'active' => true]);

    $envelope = growthEnvelope($tenant);
    app(SigningService::class)->sign($envelope->signers()->where('position', 1)->first(), 'typed', 'Signer 1', true);

    Http::assertSentCount(1); // 'envelope.sent' e 'signer.viewed' não estão na lista de eventos
    Http::assertSent(function ($request) {
        $body = $request->body();
        $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Webhook-Timestamp')[0].'.'.$body, 'whsec_abc');

        return $request->header('X-Webhook-Event')[0] === 'signer.signed'
            && hash_equals($expected, $request->header('X-Webhook-Signature')[0])
            && json_decode($body, true)['data']['signer']['email'] === 's1@example.com'
            && ! str_contains($body, 'sign.show') && ! str_contains($body, '/assinar/');
    });
    expect($endpoint->deliveries()->first()->status)->toBe('delivered');
});

it('não vaza dados de outro tenant nos webhooks', function () {
    config(['webhooks.allow_private' => true]);
    Http::fake();
    Queue::fake([\App\Jobs\SealEnvelope::class]);

    $other = Tenant::create(['name' => 'Outra']);
    WebhookEndpoint::create(['tenant_id' => $other->id, 'url' => 'http://hooks.test/other', 'secret' => 's', 'active' => true]);

    growthEnvelope(Tenant::create(['name' => 'ACME']));

    Http::assertNothingSent();
});

it('webhook que falha é repetido e marcado como falho ao esgotar as tentativas', function () {
    config(['webhooks.allow_private' => true]);
    Http::fake(['*' => Http::response('erro', 500)]);

    $tenant = Tenant::create(['name' => 'ACME']);
    $endpoint = WebhookEndpoint::create(['tenant_id' => $tenant->id, 'url' => 'http://hooks.test/in', 'secret' => 's', 'active' => true]);
    $delivery = $endpoint->deliveries()->create(['tenant_id' => $tenant->id, 'event' => 'ping', 'payload' => [], 'status' => 'pending']);

    $job = new DeliverWebhook($delivery->id);
    $job->withFakeQueueInteractions();
    $job->handle();
    expect($delivery->fresh()->status)->toBe('pending');
    $job->assertReleased();

    $delivery->update(['attempts' => 5]);
    $job2 = new DeliverWebhook($delivery->id);
    $job2->withFakeQueueInteractions();
    $job2->handle();
    expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->response_code)->toBe(500);
});

it('bloqueia URLs de webhook para a rede interna (SSRF)', function () {
    config(['webhooks.allow_private' => false]);

    expect(UrlGuard::problem('https://127.0.0.1/x'))->not->toBeNull()
        ->and(UrlGuard::problem('https://10.0.0.5/x'))->not->toBeNull()
        ->and(UrlGuard::problem('https://169.254.169.254/latest'))->not->toBeNull()
        ->and(UrlGuard::problem('http://8.8.8.8/x'))->toBe('Use uma URL https.')
        ->and(UrlGuard::problem('https://user:pass@8.8.8.8/x'))->not->toBeNull()
        ->and(UrlGuard::problem('ftp://exemplo.com/x'))->not->toBeNull()
        ->and(UrlGuard::problem('https://8.8.8.8/x'))->toBeNull();
});

it('cria, testa e remove webhooks pelo painel, mostrando o segredo uma vez', function () {
    config(['webhooks.allow_private' => true]);
    Http::fake();
    growthRegister('w@w.com', 'W');

    $this->post('/webhooks', ['url' => 'http://hooks.test/in', 'events' => ['signer.signed']])
        ->assertRedirect()->assertSessionHas('new_secret');
    $endpoint = WebhookEndpoint::firstOrFail();
    expect($endpoint->secret)->toStartWith('whsec_')->and($endpoint->events)->toBe(['signer.signed']);

    $this->get('/webhooks')->assertOk()->assertSee('hooks.test')->assertSee($endpoint->secret); // mostrado uma vez, logo após criar
    $this->get('/webhooks')->assertOk()->assertDontSee($endpoint->secret);      // e não volta a aparecer
    $this->post("/webhooks/{$endpoint->id}/testar")->assertRedirect();
    Http::assertSent(fn ($r) => $r->header('X-Webhook-Event')[0] === 'ping');

    $this->delete("/webhooks/{$endpoint->id}")->assertRedirect();
    expect(WebhookEndpoint::count())->toBe(0);
});

it('um tenant não mexe nos webhooks de outro', function () {
    config(['webhooks.allow_private' => true]);
    $other = Tenant::create(['name' => 'Outra']);
    $endpoint = WebhookEndpoint::create(['tenant_id' => $other->id, 'url' => 'http://hooks.test/o', 'secret' => 's', 'active' => true]);

    growthRegister('x@x.com', 'X');
    $this->delete("/webhooks/{$endpoint->id}")->assertNotFound();
    $this->post("/webhooks/{$endpoint->id}/alternar")->assertNotFound();
    expect(WebhookEndpoint::count())->toBe(1);
});

// ---------------------------------------------------------------- WhatsApp / SMS

it('normaliza telefones para E.164', function () {
    config(['messaging.default_country_code' => '55']);

    expect(Phone::normalize('(11) 99999-8888'))->toBe('+5511999998888')
        ->and(Phone::normalize('11 3333-4444'))->toBe('+551133334444')
        ->and(Phone::normalize('+1 415 555 0100'))->toBe('+14155550100')
        ->and(Phone::normalize('5511999998888'))->toBe('+5511999998888')
        ->and(Phone::normalize('123'))->toBeNull()
        ->and(Phone::normalize(null))->toBeNull();
});

it('convida por WhatsApp e SMS quando o envelope pede, sem quebrar se não houver telefone', function () {
    Mail::fake();
    GrowthFakeSender::$sent = [];
    app()->bind(MessageSender::class, fn () => new GrowthFakeSender());

    $envelope = growthEnvelope(Tenant::create(['name' => 'ACME']), signers: 2, options: ['channels' => ['email', 'whatsapp', 'sms']]);
    $envelope->signers()->where('position', 2)->update(['phone' => null]);

    $failed = app(SignerNotifier::class)->inviteActive($envelope->fresh());

    $sent = GrowthFakeSender::$sent;
    expect($sent)->toHaveCount(2)
        ->and($sent[0][0])->toBe('whatsapp')->and($sent[0][1])->toBe('+5511999990001')
        ->and($sent[1][0])->toBe('sms')
        ->and($sent[0][2])->toContain('/assinar/');
    Mail::assertSent(SignatureRequestedMail::class, 2); // e-mail continua funcionando para ambos
    expect($failed)->toBe([]); // o e-mail entregou, então ninguém ficou sem aviso
});

// ---------------------------------------------------------------- posição da assinatura

it('inclui no relatório as assinaturas posicionadas, só de quem já assinou', function () {
    Queue::fake();
    $tenant = Tenant::create(['name' => 'ACME']);
    $envelope = growthEnvelope($tenant, options: ['signature_fields' => [
        ['signer' => 0, 'page' => 1, 'x' => 0.1, 'y' => 0.8],
        ['signer' => 1, 'page' => 2, 'x' => 0.5, 'y' => 0.5],
    ]]);

    expect($envelope->signatureFields)->toHaveCount(2);
    expect((new ReportBuilder())->build($envelope)['placements'])->toBe([]);

    app(SigningService::class)->sign($envelope->signers()->where('position', 1)->first(), 'drawn', 'data:image/png;base64,QUJD', true);
    $placements = (new ReportBuilder())->build($envelope->fresh())['placements'];

    expect($placements)->toHaveCount(1)
        ->and($placements[0])->toMatchArray(['page' => 1, 'name' => 'Signer 1', 'type' => 'drawn', 'image' => 'QUJD', 'text' => null]);
});

it('cria envelope pelo painel com posições de assinatura e opções de entrega', function () {
    Storage::fake('local');
    growthRegister('p@p.com', 'P');
    $folder = Folder::create(['tenant_id' => auth()->user()->tenant_id, 'name' => 'Contratos']);
    $tag = Tag::create(['tenant_id' => auth()->user()->tenant_id, 'name' => 'Urgente', 'color' => 'danger']);

    $this->post('/envelopes', [
        'title' => 'Com posições', 'document' => growthPdf(), 'signing_order' => 'sequential',
        'validity_days' => 10, 'reminder_every_days' => 'off', 'channels' => ['email', 'whatsapp'],
        'folder_id' => $folder->id, 'tags' => [$tag->id],
        'signers' => [['name' => 'Ana', 'email' => 'ana@ex.com', 'phone' => '11999998888']],
        'signature_fields' => [['signer' => 0, 'page' => 1, 'x' => 0.2, 'y' => 0.9]],
    ])->assertSessionHasNoErrors();

    $envelope = Envelope::with(['tags', 'signatureFields'])->firstOrFail();
    expect($envelope->validity_days)->toBe(10)
        ->and($envelope->reminder_every_days)->toBeNull()
        ->and($envelope->channels())->toBe(['email', 'whatsapp'])
        ->and($envelope->folder_id)->toBe($folder->id)
        ->and($envelope->tags->pluck('name')->all())->toBe(['Urgente'])
        ->and($envelope->signatureFields)->toHaveCount(1)
        ->and($envelope->signing_order)->toBe(SigningOrder::Sequential);
});

// ---------------------------------------------------------------- pastas, etiquetas e busca

it('organiza e busca envelopes por pasta, etiqueta, status e texto', function () {
    Storage::fake('local');
    growthRegister('o@o.com', 'O');
    $tenantId = auth()->user()->tenant_id;

    $this->post('/organizacao/pastas', ['name' => 'RH'])->assertSessionHasNoErrors();
    $this->post('/organizacao/etiquetas', ['name' => 'Urgente', 'color' => 'danger'])->assertSessionHasNoErrors();
    $this->post('/organizacao/pastas', ['name' => 'RH'])->assertSessionHasErrors('name'); // duplicada
    $folder = Folder::where('tenant_id', $tenantId)->firstOrFail();
    $tag = Tag::where('tenant_id', $tenantId)->firstOrFail();

    $a = growthWebEnvelope();
    $this->post("/envelopes/{$a->id}/organizar", ['folder_id' => $folder->id, 'tags' => [$tag->id]])->assertRedirect();
    $this->post('/envelopes', ['title' => 'Aluguel do galpão', 'document' => growthPdf(), 'signing_order' => 'parallel',
        'signers' => [['name' => 'Zeca Silva', 'email' => 'zeca@loja.com']]])->assertSessionHasNoErrors();

    $this->get('/envelopes?folder='.$folder->id)->assertOk()->assertSee('Contrato de serviço')->assertDontSee('Aluguel do galpão');
    $this->get('/envelopes?tag='.$tag->id)->assertSee('Contrato de serviço')->assertDontSee('Aluguel do galpão');
    $this->get('/envelopes?folder=none')->assertSee('Aluguel do galpão')->assertDontSee('Contrato de serviço');
    $this->get('/envelopes?q=galpão')->assertSee('Aluguel do galpão')->assertDontSee('Contrato de serviço');
    $this->get('/envelopes?q=zeca@loja')->assertSee('Aluguel do galpão')->assertDontSee('Contrato de serviço');
    $this->get('/envelopes?q=ANA')->assertSee('Contrato de serviço'); // sem diferenciar maiúsculas, acha o signatário
    $this->get('/envelopes?status=completed')->assertSee('Nenhum documento encontrado');
    $this->get('/envelopes?q=100%25')->assertOk(); // curingas do LIKE são escapados
});

it('não aceita pasta ou etiqueta de outro tenant', function () {
    Storage::fake('local');
    $other = Tenant::create(['name' => 'Outra']);
    $foreignFolder = Folder::create(['tenant_id' => $other->id, 'name' => 'Alheia']);
    $foreignTag = Tag::create(['tenant_id' => $other->id, 'name' => 'Alheia', 'color' => 'info']);

    growthRegister('i@i.com', 'I');
    $envelope = growthWebEnvelope();

    $this->post("/envelopes/{$envelope->id}/organizar", ['folder_id' => $foreignFolder->id, 'tags' => [$foreignTag->id]])->assertSessionHasErrors('envelope');
    expect($envelope->fresh()->folder_id)->toBeNull()->and($envelope->tags()->count())->toBe(0);

    $this->post('/organizacao/pastas', ['name' => 'Minha']);
    $this->delete("/organizacao/pastas/{$foreignFolder->id}")->assertNotFound();
    $this->delete("/organizacao/etiquetas/{$foreignTag->id}")->assertNotFound();
    expect(Folder::find($foreignFolder->id))->not->toBeNull();
});

// ---------------------------------------------------------------- modelos

it('gera PDF do modelo escapando os valores e removendo HTML perigoso', function () {
    $template = new DocumentTemplate(['name' => 'T', 'body' => '<p onclick="x()">Olá {{ nome }}</p><script>alert(1)</script><img src="http://evil.test/x.png">']);

    expect($template->variables())->toBe(['nome']);
    expect(TemplateRenderer::sanitize($template->body))->toBe('<p>Olá {{ nome }}</p>alert(1)');

    $pdf = (new TemplateRenderer())->render($template, ['nome' => '<b>José</b> & Cia']);
    expect(str_starts_with($pdf, '%PDF-'))->toBeTrue()->and(strlen($pdf))->toBeGreaterThan(1000);
});

it('cria um modelo e gera um envelope a partir dele pelo painel', function () {
    Storage::fake('local');
    growthRegister('t@t.com', 'T');

    $this->post('/modelos', ['name' => 'Contrato simples', 'body' => '<h2>Contrato</h2><p>Contratante: {{contratante}} - R$ {{valor}}</p>'])->assertRedirect();
    $template = DocumentTemplate::firstOrFail();
    expect($template->variables())->toBe(['contratante', 'valor']);

    $this->get("/modelos/{$template->id}/usar")->assertOk()->assertSee('contratante');
    $this->get("/modelos/{$template->id}/previa")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->post("/modelos/{$template->id}/usar", ['title' => 'Contrato Zé', 'signing_order' => 'parallel', 'vars' => ['contratante' => 'Zé'], 'signers' => [['name' => 'Zé', 'email' => 'ze@ex.com']]])
        ->assertSessionHasErrors('vars.valor'); // variável obrigatória

    $this->post("/modelos/{$template->id}/usar", ['title' => 'Contrato Zé', 'signing_order' => 'parallel', 'vars' => ['contratante' => 'Zé', 'valor' => '1.500,00'], 'signers' => [['name' => 'Zé', 'email' => 'ze@ex.com']]])
        ->assertSessionHasNoErrors();

    $envelope = Envelope::firstOrFail();
    expect($envelope->title)->toBe('Contrato Zé')->and($envelope->status)->toBe(EnvelopeStatus::Draft);
    expect(str_starts_with(Storage::disk('local')->get($envelope->document->storage_path), '%PDF-'))->toBeTrue();
});

it('um tenant não vê nem usa modelo de outro', function () {
    $other = Tenant::create(['name' => 'Outra']);
    $template = DocumentTemplate::create(['tenant_id' => $other->id, 'name' => 'Alheio', 'body' => '<p>x</p>']);

    growthRegister('n@n.com', 'N');
    $this->get("/modelos/{$template->id}")->assertNotFound();
    $this->get("/modelos/{$template->id}/usar")->assertNotFound();
    $this->get("/modelos/{$template->id}/previa")->assertNotFound();
    $this->delete("/modelos/{$template->id}")->assertNotFound();
});

// ---------------------------------------------------------------- limite de PDF

it('recusa PDF acima do limite configurado', function () {
    Storage::fake('local');
    config(['signing.max_pdf_mb' => 1]);

    expect(fn () => app(EnvelopeService::class)->create(
        Tenant::create(['name' => 'ACME']), null, "%PDF-1.4\n".str_repeat('a', 1024 * 1024 + 10), 'g.pdf', 'Grande',
        [['name' => 'A', 'email' => 'a@a.com']],
    ))->toThrow(FlowException::class, 'excede o limite de 1 MB');
});

it('a validação do painel também respeita o limite de PDF', function () {
    Storage::fake('local');
    config(['signing.max_pdf_mb' => 1]);
    growthRegister('l@l.com', 'L');

    $big = \Illuminate\Http\UploadedFile::fake()->create('grande.pdf', 2048, 'application/pdf');
    $this->post('/envelopes', ['title' => 'G', 'document' => $big, 'signing_order' => 'parallel', 'signers' => [['name' => 'A', 'email' => 'a@a.com']]])
        ->assertSessionHasErrors('document');
});

// ---------------------------------------------------------------- saúde do sistema

it('painel de saúde só abre para super admin e não quebra com serviços fora do ar', function () {
    config(['platform.super_admins' => ['root@plataforma.com']]);
    Http::fake(['*' => Http::response('', 503)]);

    $client = User::create(['tenant_id' => Tenant::create(['name' => 'Cliente'])->id, 'name' => 'C', 'email' => 'c@c.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $this->actingAs($client)->get('/admin/saude')->assertNotFound();

    $admin = User::create(['tenant_id' => Tenant::create(['name' => 'Interna'])->id, 'name' => 'R', 'email' => 'root@plataforma.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $this->actingAs($admin)->get('/admin/saude')->assertOk()
        ->assertSee('Serviço de selagem')->assertSee('Fila (worker)')->assertSee('Uso por empresa')
        ->assertSee('Defina SENTRY_LARAVEL_DSN');
});

it('detecta selagem travada e envelope preso na verificação de saúde', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $envelope = growthEnvelope(Tenant::create(['name' => 'ACME']));
    $envelope->forceFill(['status' => EnvelopeStatus::Sealing, 'updated_at' => now()->subHour()])->saveQuietly();

    $checks = collect(app(SystemHealth::class)->checks())->keyBy('label');

    expect($checks['Selagens travadas']['status'])->toBe('warn')
        ->and($checks['Serviço de selagem']['status'])->toBe('ok')
        ->and($checks['Banco de dados']['status'])->toBe('ok');
});

it('o scrubber do Sentry remove tokens de assinatura da URL', function () {
    expect(\App\Support\SentryScrubber::url('https://app.exemplo.com/assinar/AbC123tokenSECRETO/selfie?x=1'))
        ->toBe('https://app.exemplo.com/assinar/{token}/selfie?x=1')
        ->and(\App\Support\SentryScrubber::url('https://app.exemplo.com/verificar/ABCDEF123456'))
        ->toBe('https://app.exemplo.com/verificar/{codigo}');
});

it('envio manual (sem e-mail) não avisa ninguém sozinho, nem o próximo da fila nem lembretes', function () {
    Mail::fake();
    Storage::fake('local');
    growthRegister('m@m.com', 'M');
    $this->post('/envelopes', ['title' => 'Manual', 'document' => growthPdf(), 'signing_order' => 'sequential',
        'signers' => [['name' => 'Ana', 'email' => 'ana@ex.com'], ['name' => 'Beto', 'email' => 'beto@ex.com']]])->assertSessionHasNoErrors();
    $envelope = Envelope::firstOrFail();

    $this->post("/envelopes/{$envelope->id}/enviar", ['skip_email' => 1])->assertRedirect();
    Queue::fake([\App\Jobs\SealEnvelope::class]);
    app(SigningService::class)->sign($envelope->signers()->where('position', 1)->first(), 'typed', 'Ana', true);
    $envelope->signers()->update(['invited_at' => now()->subDays(30)]);
    $this->artisan('envelopes:remind')->expectsOutputToContain('0 lembrete');

    Mail::assertNothingSent();
    expect($envelope->fresh()->channels())->toBe(['manual']);
});
