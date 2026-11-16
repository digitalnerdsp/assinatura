<?php

declare(strict_types=1);

use App\Api\Presenter;
use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SenderPolicy;
use App\Envelopes\SigningService;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\TenantSigningSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    Queue::fake([\App\Jobs\SealEnvelope::class]);
});

/** @return array{0:User,1:Envelope,2:Signer,3:Signer} Ana exige código e selfie; Beto não exige nada */
function wzTwoLevels(): array
{
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [
        wzPerson('Ana', 'ana@x.com', 'signer', ['require_otp' => true, 'require_selfie' => true]),
        wzPerson('Beto', 'beto@x.com'),
    ]);
    [$ana, $beto] = $e->refresh()->signers()->orderBy('position')->get()->all();

    return [$u, $e, $ana, $beto];
}

it('cada signatário guarda as próprias verificações e o documento não muda', function () {
    [, $e, $ana, $beto] = wzTwoLevels();

    expect($ana->require_otp)->toBeTrue()->and($ana->require_selfie)->toBeTrue()->and($ana->require_document)->toBeFalse()->and($ana->require_geolocation)->toBeFalse()
        ->and($beto->require_otp)->toBeFalse()->and($beto->require_selfie)->toBeFalse()
        ->and($e->require_otp)->toBeFalse()->and($e->require_selfie)->toBeFalse();
});

it('o passo 2 grava e mostra as verificações por signatário', function () {
    $u = wzOwner();
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'never']);
    $e = wzDraft($u);

    $this->actingAs($u)->put("/documentos/{$e->id}/passo/2", ['sender_signs' => 0, 'people' => [
        ['name' => 'Ana', 'email' => 'ana@x.com', 'role' => 'signer', 'require_geolocation' => 1, 'require_document' => 1],
        ['name' => 'Beto', 'email' => 'beto@x.com', 'role' => 'signer'],
    ]])->assertRedirect("/documentos/{$e->id}/passo/3");

    [$ana, $beto] = $e->signers()->orderBy('position')->get()->all();
    expect($ana->require_geolocation)->toBeTrue()->and($ana->require_document)->toBeTrue()->and($ana->require_otp)->toBeFalse()
        ->and($beto->require_geolocation)->toBeFalse()->and($beto->require_document)->toBeFalse();

    $html = $this->actingAs($u)->get("/documentos/{$e->id}/passo/2")->assertOk()->getContent();
    expect($html)->toContain('name="people[0][require_geolocation]"')->and($html)->toContain('Verificações deste signatário');
    expect(preg_match('/name="people\[0\]\[require_geolocation\]"[^>]*checked/', $html))->toBe(1)
        ->and(preg_match('/name="people\[1\]\[require_geolocation\]"[^>]*checked/', $html))->toBe(0);
});

it('não existe mais o bloco de verificações do documento inteiro no passo 2', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/2")->assertOk()->assertDontSee('Verificações do documento')->assertSee('Verificações deste signatário');
});

it('as etapas pendentes seguem o signatário, não o documento', function () {
    [, , $ana, $beto] = wzTwoLevels();
    $svc = app(SigningService::class);

    expect($svc->pendingSteps($ana))->toBe(['otp', 'selfie'])->and($svc->pendingSteps($beto))->toBe([]);
});

it('quem não exige nada assina direto; quem exige código é barrado até confirmar', function () {
    [$u, $e, $ana, $beto] = wzTwoLevels();
    app(EnvelopeService::class)->syncFields($e->refresh(), []);
    app(EnvelopeService::class)->send($e->refresh());
    $svc = app(SigningService::class);

    $svc->sign($beto->fresh(), 'typed', 'Beto', true);
    expect($beto->fresh()->status->value)->toBe('signed')->and($beto->fresh()->signature->level)->toBe('simple');

    expect(fn () => $svc->sign($ana->fresh(), 'typed', 'Ana', true))->toThrow(FlowException::class, 'código');
});

it('o nível avançado é por signatário (geolocalização sozinha continua simples)', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com', 'signer', ['require_geolocation' => true]), wzPerson('Bia', 'bia@x.com', 'signer', ['require_document' => true]), wzPerson('Cid', 'cid@x.com')]);
    [$ana, $bia, $cid] = $e->refresh()->signers()->orderBy('position')->get()->all();

    expect($ana->requiresAdvanced())->toBeFalse()->and($bia->requiresAdvanced())->toBeTrue()->and($cid->requiresAdvanced())->toBeFalse();
});

it('as verificações do documento inteiro (API) continuam valendo para todos', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com'), wzPerson('Beto', 'beto@x.com')]);
    $e->update(['require_selfie' => true]);
    [$ana, $beto] = $e->refresh()->signers()->orderBy('position')->get()->all();

    expect($ana->requires('selfie'))->toBeTrue()->and($beto->requires('selfie'))->toBeTrue()->and(app(SigningService::class)->pendingSteps($beto))->toBe(['selfie']);
});

it('código por e-mail exige e-mail só de quem recebe a exigência', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', null, 'signer', ['require_otp' => true]), wzPerson('Beto')]);
    $e->update(['notify_channels' => ['manual']]);

    expect(fn () => app(EnvelopeService::class)->assertSendable($e->refresh(), false))->toThrow(FlowException::class, 'código');

    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com', 'signer', ['require_otp' => true, 'id' => $e->signers()->where('name', 'Ana')->first()->id]), wzPerson('Beto', null, 'signer', ['id' => $e->signers()->where('name', 'Beto')->first()->id])]);
    app(EnvelopeService::class)->assertSendable($e->refresh(), false); // Beto sem e-mail, sem exigência: passa no manual
    expect(true)->toBeTrue();
});

it('a finalização aponta o signatário que exige código e está sem e-mail', function () {
    $u = wzOwner();
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'never']);
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', null, 'signer', ['require_otp' => true]), wzPerson('Beto')]);
    app(EnvelopeService::class)->syncFields($e->refresh(), []);
    [$ana, $beto] = $e->signers()->orderBy('position')->get()->all();

    $this->actingAs($u)->post("/documentos/{$e->id}/finalizar", ['auto' => 0])->assertSessionHasErrors("emails.{$ana->id}")->assertSessionDoesntHaveErrors("emails.{$beto->id}");
});

it('a API mostra as verificações de cada signatário', function () {
    [, $e, $ana, $beto] = wzTwoLevels();
    $payload = Presenter::envelope($e->fresh());
    $byName = collect($payload['signers'])->keyBy('name');

    expect($byName['Ana']['verifications'])->toBe(['otp' => true, 'selfie' => true, 'document' => false, 'geolocation' => false])
        ->and($byName['Beto']['verifications'])->toBe(['otp' => false, 'selfie' => false, 'document' => false, 'geolocation' => false]);
});

// ------------------------------------------------------------------ remetente: padrão opcional

it('sem configuração da empresa, assinar como remetente é opcional por padrão', function () {
    $u = wzOwner();
    expect(app(SenderPolicy::class)->policy($u->tenant))->toBe('optional');

    $e = wzDraft($u);
    $html = $this->actingAs($u)->get("/documentos/{$e->id}/passo/2")->assertOk()->assertSee('Eu vou assinar esse documento')->getContent();
    expect(preg_match('/id="wz-me"[^>]*checked/', $html))->toBe(0); // começa desligado: a pessoa escolhe em cada documento

    $this->actingAs($u)->put("/documentos/{$e->id}/passo/2", ['sender_signs' => 0, 'people' => [['name' => 'Ana', 'role' => 'signer']]])->assertRedirect("/documentos/{$e->id}/passo/3");
    expect($e->signers()->where('is_sender', true)->count())->toBe(0);
});

it('quem escolhe assinar num documento precisa da assinatura salva, nos outros não', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $this->actingAs($u)->put("/documentos/{$e->id}/passo/2", ['sender_signs' => 1, 'people' => [['name' => 'Ana', 'role' => 'signer']]])->assertSessionHasErrors('sender');

    $u->forceFill(['signature_type' => 'typed', 'signature_data' => 'Dono'])->save();
    $this->actingAs($u->fresh())->put("/documentos/{$e->id}/passo/2", ['sender_signs' => 1, 'people' => [['name' => 'Ana', 'role' => 'signer']]])->assertRedirect("/documentos/{$e->id}/passo/3");
    expect($e->signers()->where('is_sender', true)->count())->toBe(1);
});

it('a tela de configuração da empresa marca "Opcional" como padrão', function () {
    $u = wzOwner();
    $html = $this->actingAs($u)->get('/configuracoes/assinatura-da-empresa')->assertOk()->getContent();

    expect(preg_match('/id="policy_optional"[^>]*checked/', $html))->toBe(1)->and(preg_match('/id="policy_always"[^>]*checked/', $html))->toBe(0);
});
