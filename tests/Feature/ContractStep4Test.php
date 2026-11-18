<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Envelopes\EnvelopeService;
use App\Models\Envelope;
use App\Models\TenantSigningSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-18 15:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

/** @return array{0:User,1:Envelope} rascunho nos passos 1 a 3 concluídos, com a Ana (e-mail) como signatária */
function ctAtStep4(): array
{
    $u = wzOwner('dono@wz.test');
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'never']);
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com')]);
    app(EnvelopeService::class)->syncFields($e->refresh(), []);

    return [$u, $e->refresh()];
}

function ctOpts(array $over = []): array
{
    return $over + ['auto' => 1, 'watch' => 1, 'contract_date' => '2027-06-30', 'contract_alert_days' => 30, 'contract_emails' => ['dono@wz.test']];
}

it('o passo 4 traz o bloco de vencimento desligado e com o e-mail de quem cria', function () {
    [$u, $e] = ctAtStep4();
    $html = $this->actingAs($u)->get("/documentos/{$e->id}/passo/4")->assertOk()
        ->assertSee('Lembrar do vencimento deste contrato?')->assertSee('Data de vencimento')->assertSee('Avisar quantos dias antes')->assertSee('Avisar nestes e-mails')
        ->assertSee('value="dono@wz.test"', false)->getContent();

    expect(preg_match('/name="watch" value="1"[^>]*checked/', $html))->toBe(0)->and($html)->toContain('<option value="90"');
});

it('desligado não grava nada e limpa vigência antiga', function () {
    [$u, $e] = ctAtStep4();
    $this->actingAs($u)->put("/documentos/{$e->id}/passo/4", ctOpts())->assertSessionHasNoErrors();
    expect($e->fresh()->contract_expires_on)->not->toBeNull();

    $this->actingAs($u)->put("/documentos/{$e->id}/passo/4", ['auto' => 1, 'watch' => 0])->assertSessionHasNoErrors();
    $e->refresh();
    expect($e->contract_expires_on)->toBeNull()->and($e->contract_alert_emails)->toBeNull();
});

it('ligado grava a vigência e ela segue com o documento depois do envio', function () {
    [$u, $e] = ctAtStep4();
    $this->actingAs($u)->post("/documentos/{$e->id}/finalizar", ctOpts(['contract_emails' => ['Dono@wz.test', 'juridico@wz.test'], 'contract_alert_days' => 60, 'emails' => [$e->signers()->first()->id => 'ana@x.com']]))
        ->assertRedirect("/documentos/{$e->id}");

    $e->refresh();
    expect($e->status)->toBe(EnvelopeStatus::Sent)->and($e->contract_expires_on->toDateString())->toBe('2027-06-30')->and($e->contract_alert_days)->toBe(60)
        ->and($e->contract_alert_emails)->toBe(['dono@wz.test', 'juridico@wz.test']);
});

it('vigência inválida barra o envio com a mensagem e nada é enviado', function (array $over, string $message) {
    [$u, $e] = ctAtStep4();
    $this->actingAs($u)->post("/documentos/{$e->id}/finalizar", ctOpts($over + ['emails' => [$e->signers()->first()->id => 'ana@x.com']]))->assertSessionHasErrors('contract');

    expect(session('errors')->first('contract'))->toContain($message);
    expect($e->fresh()->status)->toBe(EnvelopeStatus::Draft)->and($e->fresh()->contract_expires_on)->toBeNull();
    Mail::assertNothingSent();
})->with([
    'data passada' => [['contract_date' => '2026-11-01'], 'depois de hoje'],
    'hoje' => [['contract_date' => '2026-11-18'], 'depois de hoje'],
    'sem data' => [['contract_date' => ''], 'formato'],
    'dias fora da lista' => [['contract_alert_days' => 10], 'dias de antecedência'],
    'sem e-mail' => [['contract_emails' => []], 'ao menos um e-mail'],
    'e-mail inválido' => [['contract_emails' => ['nao-e']], 'não parece válido'],
    'seis e-mails' => [['contract_emails' => ['a@x.com', 'b@x.com', 'c@x.com', 'd@x.com', 'e@x.com', 'f@x.com']], 'no máximo 5'],
]);

it('Voltar grava o que for válido e ignora o inválido sem bloquear', function () {
    [$u, $e] = ctAtStep4();
    $this->actingAs($u)->put("/documentos/{$e->id}/passo/4", ctOpts(['go' => 'back']))->assertRedirect("/documentos/{$e->id}/passo/3");
    expect($e->fresh()->contract_expires_on->toDateString())->toBe('2027-06-30');

    $this->actingAs($u)->put("/documentos/{$e->id}/passo/4", ctOpts(['go' => 'back', 'contract_date' => '2020-01-01']))->assertRedirect("/documentos/{$e->id}/passo/3")->assertSessionHasNoErrors();
    expect($e->fresh()->contract_expires_on->toDateString())->toBe('2027-06-30'); // o inválido não trocou o que já estava
});

it('ao reabrir o passo 4 os dados da vigência voltam preenchidos', function () {
    [$u, $e] = ctAtStep4();
    $this->actingAs($u)->put("/documentos/{$e->id}/passo/4", ctOpts(['contract_alert_days' => 15, 'contract_emails' => ['dono@wz.test', 'outro@wz.test']]))->assertSessionHasNoErrors();

    $html = $this->actingAs($u)->get("/documentos/{$e->id}/passo/4")->assertOk()->getContent();
    expect(preg_match('/name="watch" value="1"[^>]*checked/', $html))->toBe(1)->and($html)->toContain('value="2027-06-30"')->and($html)->toContain('value="outro@wz.test"')
        ->and(preg_match('/<option value="15" selected/', $html))->toBe(1);
});

it('o modo manual também guarda a vigência', function () {
    [$u, $e] = ctAtStep4();
    $this->actingAs($u)->post("/documentos/{$e->id}/finalizar", ctOpts(['auto' => 0]))->assertRedirect("/documentos/{$e->id}");
    expect($e->fresh()->contract_expires_on)->not->toBeNull()->and($e->fresh()->status)->toBe(EnvelopeStatus::Sent);
});
