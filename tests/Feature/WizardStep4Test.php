<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Envelopes\EnvelopeService;
use App\Mail\SignatureRequestedMail;
use App\Models\Envelope;
use App\Models\Tag;
use App\Models\TenantSigningSetting;
use App\Models\UsageEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
});

/** @return array{0:User,1:Envelope} rascunho com Ana e Beto, passos 1 a 3 concluídos, política opcional */
function wzAtStep4(bool $withEmails = false, bool $sender = false): array
{
    $u = wzOwner();
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'optional']);
    if ($sender) {
        $u->forceFill(['signature_type' => 'typed', 'signature_data' => 'Dono'])->save();
    }
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', $withEmails ? 'ana@x.com' : null), wzPerson('Beto', $withEmails ? 'beto@x.com' : null)], $sender);
    app(EnvelopeService::class)->syncFields($e->refresh(), []);

    return [$u, $e->refresh()];
}

function wzFinish(User $u, Envelope $e, array $data = [])
{
    return test()->actingAs($u)->post("/documentos/{$e->id}/finalizar", $data);
}

function wzEmails(Envelope $e, array $by = ['Ana' => 'ana@x.com', 'Beto' => 'beto@x.com']): array
{
    return $e->signers()->where('is_sender', false)->get()->mapWithKeys(fn ($s) => [$s->id => $by[$s->name] ?? ''])->all();
}

it('o passo 4 mostra as opções de envio', function () {
    [$u, $e] = wzAtStep4();
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/4")->assertOk()
        ->assertSee('Envie o documento')->assertSee('Ativar envio automático dos links')->assertSee('Ana')->assertSee('Beto')
        ->assertSee('Mensagem personalizada')->assertSee('Ativar lembretes automáticos')->assertSee('Data limite para assinatura do documento')
        ->assertSee('Permitir recusar o documento')->assertSee('ENVIAR E FINALIZAR')->assertSee('VOLTAR');
});

it('o passo 4 só abre depois do passo 3', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana')]);
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/4")->assertRedirect("/documentos/{$e->id}/passo/3");
});

it('envia com envio automático: libera, convida cada signatário e vai ao detalhe', function () {
    [$u, $e] = wzAtStep4();
    $res = wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e), 'message' => 'Olá, assine por favor', 'remind' => 1, 'remind_days' => 5, 'channels' => ['email']]);

    $res->assertRedirect("/documentos/{$e->id}")->assertSessionHas('status');
    $e->refresh();
    expect($e->status)->toBe(EnvelopeStatus::Sent)->and($e->message)->toBe('Olá, assine por favor')->and($e->reminder_every_days)->toBe(5)
        ->and($e->channels())->toBe(['email'])->and($e->signers()->pluck('email')->sort()->values()->all())->toBe(['ana@x.com', 'beto@x.com']);
    Mail::assertSent(SignatureRequestedMail::class, 2);
});

it('com envio automático o e-mail de cada signatário é obrigatório e nada é enviado', function () {
    [$u, $e] = wzAtStep4();
    $ids = $e->signers()->pluck('id', 'name');
    wzFinish($u, $e, ['auto' => 1, 'emails' => [$ids['Ana'] => 'ana@x.com', $ids['Beto'] => '']])->assertSessionHasErrors("emails.{$ids['Beto']}");
    wzFinish($u, $e, ['auto' => 1, 'emails' => [$ids['Ana'] => 'ana@x.com', $ids['Beto'] => 'nao-e-email']])->assertSessionHasErrors();
    expect($e->fresh()->status)->toBe(EnvelopeStatus::Draft);
    Mail::assertNothingSent();
});

it('modo manual: não pede e-mail, não avisa ninguém, sem lembretes', function () {
    [$u, $e] = wzAtStep4();
    wzFinish($u, $e, ['auto' => 0, 'remind' => 1, 'remind_days' => 3])->assertRedirect("/documentos/{$e->id}")
        ->assertSessionHas('status', 'Documento liberado para assinatura. Copie o link de cada signatário abaixo e envie como preferir.');

    $e->refresh();
    expect($e->status)->toBe(EnvelopeStatus::Sent)->and($e->channels())->toBe(['manual'])->and($e->reminder_every_days)->toBeNull();
    Mail::assertNothingSent();
});

it('o remetente assina ao finalizar e os demais recebem o convite', function () {
    [$u, $e] = wzAtStep4(true, true);
    Queue::fake([\App\Jobs\SealEnvelope::class]);
    wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e)])->assertRedirect("/documentos/{$e->id}");

    $sender = $e->signers()->where('is_sender', true)->first();
    expect($sender->fresh()->status)->toBe(SignerStatus::Signed)->and($e->fresh()->status)->toBe(EnvelopeStatus::InProgress);
    Mail::assertSent(SignatureRequestedMail::class, 2);
});

it('sem assinatura salva em Meu perfil o remetente não finaliza e o rascunho fica', function () {
    [$u, $e] = wzAtStep4(true, true);
    $u->forceFill(['signature_type' => null, 'signature_data' => null])->save();
    wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e)])->assertSessionHasErrors('options');
    expect($e->fresh()->status)->toBe(EnvelopeStatus::Draft);
});

it('a data limite vira os dias de validade e mensagem acima de 500 é recusada', function () {
    [$u, $e] = wzAtStep4(true);
    wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e), 'message' => str_repeat('a', 501)])->assertSessionHasErrors('message');
    wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e), 'limit' => 1, 'limit_date' => now()->subDay()->toDateString()])->assertSessionHasErrors('limit_date');
    wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e), 'limit' => 1, 'limit_date' => now()->addDays(400)->toDateString()])->assertSessionHasErrors('limit_date');

    wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e), 'limit' => 1, 'limit_date' => now()->addDays(10)->toDateString()])->assertRedirect("/documentos/{$e->id}");
    expect($e->fresh()->validity_days)->toBeBetween(10, 11);
});

it('permitir recusar e etiquetas são gravados', function () {
    [$u, $e] = wzAtStep4(true);
    $tag = Tag::create(['tenant_id' => $u->tenant_id, 'name' => 'Urgente', 'color' => 'danger']);
    wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e), 'allow_refuse' => 1, 'tags' => [$tag->id]])->assertRedirect("/documentos/{$e->id}");

    expect($e->fresh()->allow_refuse)->toBeTrue()->and($e->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
});

it('o segundo clique em Enviar e finalizar não envia nem cobra de novo', function () {
    [$u, $e] = wzAtStep4(true);
    $data = ['auto' => 1, 'emails' => wzEmails($e)];
    wzFinish($u, $e, $data)->assertRedirect("/documentos/{$e->id}");
    $entries = UsageEntry::count();
    $mails = count(Mail::sent(SignatureRequestedMail::class));

    wzFinish($u, $e, $data)->assertRedirect("/documentos/{$e->id}")->assertSessionHasNoErrors();
    expect(UsageEntry::count())->toBe($entries)->and(count(Mail::sent(SignatureRequestedMail::class)))->toBe($mails);
});

it('salvar o passo 4 depois do envio não altera nada', function () {
    [$u, $e] = wzAtStep4(true);
    wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e), 'message' => 'Original'])->assertRedirect();
    $this->actingAs($u)->put("/documentos/{$e->id}/passo/4", ['auto' => 1, 'message' => 'Trocada'])->assertRedirect("/documentos/{$e->id}");
    expect($e->fresh()->message)->toBe('Original');
});

it('o limite do plano barra o envio e mantém o rascunho', function () {
    [$u, $e] = wzAtStep4(true);
    $u->tenant->update(['monthly_envelope_limit' => 0]);
    wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e)])->assertSessionHasErrors('options');
    expect($e->fresh()->status)->toBe(EnvelopeStatus::Draft);
    Mail::assertNothingSent();
});

it('Voltar grava as opções e leva ao passo 3', function () {
    [$u, $e] = wzAtStep4();
    $this->actingAs($u)->put("/documentos/{$e->id}/passo/4", ['go' => 'back', 'auto' => 1, 'message' => 'Rascunho de mensagem', 'allow_refuse' => 1])->assertRedirect("/documentos/{$e->id}/passo/3");
    expect($e->fresh()->message)->toBe('Rascunho de mensagem')->and($e->fresh()->allow_refuse)->toBeTrue();
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/4")->assertSee('Rascunho de mensagem');
});

it('sem permitir recusar a tela do signatário esconde o botão e a recusa dá 403', function () {
    [$u, $e] = wzAtStep4(true);
    wzFinish($u, $e, ['auto' => 1, 'emails' => wzEmails($e)])->assertRedirect();
    $token = \Illuminate\Support\Facades\Crypt::decryptString($e->signers()->where('name', 'Ana')->first()->token_encrypted);

    $this->get("/assinar/{$token}")->assertOk()->assertDontSee('Recusar assinatura');
    $this->post("/assinar/{$token}/recusar", ['reason' => 'Não concordo'])->assertForbidden();
    expect($e->signers()->where('name', 'Ana')->first()->status)->not->toBe(SignerStatus::Refused);
});

it('documentos da API e de modelos continuam podendo ser recusados', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com')]);
    $e->update(['allow_refuse' => true]);
    $tokens = app(EnvelopeService::class)->send($e->refresh());
    $token = array_values($tokens)[0];

    $this->get("/assinar/{$token}")->assertOk()->assertSee('Recusar assinatura');
    $this->post("/assinar/{$token}/recusar", ['reason' => 'Não concordo'])->assertOk();
    expect($e->signers()->first()->status)->toBe(SignerStatus::Refused);
});

it('Criar documento leva ao assistente', function () {
    $u = wzOwner();
    $this->actingAs($u)->get('/documentos')->assertOk()->assertSee(route('envelopes.create'), false);
    expect(route('envelopes.create', [], false))->toBe('/documentos/novo');
    $this->actingAs($u)->get('/documentos/novo')->assertOk()->assertSee('Quais documentos serão assinados?');
});
