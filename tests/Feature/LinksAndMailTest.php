<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Mail\SignatureRequestedMail;
use App\Mail\TestMail;
use App\Models\Envelope;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function ownerWithEnvelope(): array
{
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);
    $user = User::create(['tenant_id' => $tenant->id, 'name' => 'Dono', 'email' => 'dono@acme.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $envelope = app(EnvelopeService::class)->create($tenant, $user, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'Contrato', [
        ['name' => 'Ana', 'email' => 'ana@ex.com'], ['name' => 'Beto', 'email' => 'beto@ex.com'],
    ]);

    return [$user, $envelope];
}

it('libera o envelope sem e-mail e mostra o link de cada signatário para copiar', function () {
    Mail::fake();
    [$user, $envelope] = ownerWithEnvelope();

    $this->actingAs($user)->post("/envelopes/{$envelope->id}/enviar", ['skip_email' => 1])->assertRedirect();
    Mail::assertNothingSent();

    $ana = $envelope->signers()->where('email', 'ana@ex.com')->first();
    $url = $ana->signUrl();
    expect($url)->toStartWith(url('/assinar/'));

    $this->get("/envelopes/{$envelope->id}")->assertOk()->assertSee($url, false)->assertSee('Copiar link');
    // o link copiado realmente abre a página de assinatura
    $this->post('/sair');
    $this->get($url)->assertOk()->assertSee('Olá, ');
});

it('o token fica cifrado no banco e só o hash identifica o signatário', function () {
    [$user, $envelope] = ownerWithEnvelope();
    app(EnvelopeService::class)->send($envelope);

    $ana = $envelope->signers()->first();
    $token = basename($ana->signUrl());

    expect($ana->token_encrypted)->not->toContain($token)
        ->and($ana->token_hash)->toBe(hash('sha256', $token));
});

it('gerar novo link invalida o anterior', function () {
    [$user, $envelope] = ownerWithEnvelope();
    app(EnvelopeService::class)->send($envelope);
    $ana = $envelope->signers()->first();
    $old = $ana->signUrl();

    $this->actingAs($user)->post("/envelopes/{$envelope->id}/signatarios/{$ana->id}/novo-link")->assertRedirect();

    $new = $ana->fresh()->signUrl();
    expect($new)->not->toBe($old);
    auth()->logout();
    $this->get($old)->assertOk()->assertSee('Link de assinatura inválido');
    $this->get($new)->assertOk()->assertDontSee('Link de assinatura inválido');
});

it('reenvia o e-mail com o link atual', function () {
    Mail::fake();
    [$user, $envelope] = ownerWithEnvelope();
    app(EnvelopeService::class)->send($envelope);
    $ana = $envelope->signers()->first();

    $this->actingAs($user)->post("/envelopes/{$envelope->id}/signatarios/{$ana->id}/reenviar")->assertRedirect();

    Mail::assertSent(SignatureRequestedMail::class, fn ($m) => $m->url === $ana->signUrl());
});

it('falha de SMTP não derruba o envio: envelope liberado e o link continua disponível', function () {
    [$user, $envelope] = ownerWithEnvelope();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection refused'));

    $this->actingAs($user)->post("/envelopes/{$envelope->id}/enviar")
        ->assertRedirect()->assertSessionHasErrors('envelope');

    expect($envelope->fresh()->status->value)->toBe('sent')
        ->and($envelope->signers()->first()->signUrl())->not->toBeNull();
});

it('não deixa um usuário gerar link de signatário de outra empresa', function () {
    [, $envelope] = ownerWithEnvelope();
    app(EnvelopeService::class)->send($envelope);
    $other = User::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'X', 'email' => 'x@x.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $ana = $envelope->signers()->first();

    $this->actingAs($other)->post("/envelopes/{$envelope->id}/signatarios/{$ana->id}/novo-link")->assertNotFound();
    $this->post("/envelopes/{$envelope->id}/signatarios/{$ana->id}/reenviar")->assertNotFound();
});

/* ---------- configuração de e-mail no painel admin ---------- */

function mailAdmin(): User
{
    config(['platform.super_admins' => ['admin@plataforma.com']]);

    return User::create(['tenant_id' => Tenant::create(['name' => 'Plataforma'])->id, 'name' => 'Adm', 'email' => 'admin@plataforma.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
}

it('salva o SMTP cifrando a senha e aplica na hora', function () {
    $this->actingAs(mailAdmin())->put('/admin/email', [
        'host' => 'smtp.exemplo.com', 'port' => 587, 'username' => 'user', 'password' => 'segredo-smtp',
        'encryption' => 'tls', 'from_address' => 'nao-responda@exemplo.com', 'from_name' => 'Minha Empresa',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Setting::get('mail'))->not->toContain('segredo-smtp');
    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.exemplo.com')
        ->and(config('mail.mailers.smtp.password'))->toBe('segredo-smtp')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtp')
        ->and(config('mail.from.address'))->toBe('nao-responda@exemplo.com');

    // senha em branco mantém a atual; SSL usa o esquema smtps
    $this->put('/admin/email', [
        'host' => 'smtp.exemplo.com', 'port' => 465, 'username' => 'user', 'password' => '',
        'encryption' => 'ssl', 'from_address' => 'nao-responda@exemplo.com', 'from_name' => 'Minha Empresa',
    ])->assertSessionHasNoErrors();
    expect(MailSettings::load()['password'])->toBe('segredo-smtp')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps');

    // a página não vaza a senha
    $this->get('/admin/email')->assertOk()->assertDontSee('segredo-smtp');
});

it('envia o e-mail de teste e mostra o erro real quando falha', function () {
    $this->actingAs(mailAdmin());

    Mail::fake();
    $this->post('/admin/email/teste', ['to' => 'eu@exemplo.com'])->assertRedirect()->assertSessionHas('status');
    Mail::assertSent(TestMail::class, fn ($m) => $m->hasTo('eu@exemplo.com'));

    Mail::shouldReceive('to')->andThrow(new RuntimeException('Authentication failed: 535'));
    $this->post('/admin/email/teste', ['to' => 'eu@exemplo.com'])->assertSessionHasErrors(['test' => 'Falha ao enviar: Authentication failed: 535']);
});

it('só super admin acessa a configuração de e-mail', function () {
    [$user] = ownerWithEnvelope();
    $this->actingAs($user)->get('/admin/email')->assertNotFound();
    $this->put('/admin/email', ['host' => 'x'])->assertNotFound();
    $this->post('/admin/email/teste', ['to' => 'a@a.com'])->assertNotFound();
});
