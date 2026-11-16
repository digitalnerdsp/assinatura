<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Models\ApiKey;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\TenantSigningSetting;
use App\Models\UsageEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
});

afterEach(fn () => Carbon::setTestNow());

// ------------------------------------------------------------------ I1: o envio antigo respeita as travas

it('liberar um rascunho sem signatários pelo botão do detalhe é recusado e não cobra', function () {
    $u = wzOwner();
    $e = wzDraft($u);

    $this->actingAs($u)->post("/documentos/{$e->id}/enviar")->assertSessionHasErrors('envelope');

    expect($e->fresh()->status)->toBe(EnvelopeStatus::Draft)->and(UsageEntry::count())->toBe(0);
});

it('a API também não libera rascunho sem signatário', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    [, $plain] = ApiKey::issue($u->tenant, 'k');

    $res = $this->postJson("/api/v1/documents/{$e->id}/send", [], ['Authorization' => "Bearer {$plain}"]);

    expect($res->status())->toBeGreaterThanOrEqual(400)->and($e->fresh()->status)->toBe(EnvelopeStatus::Draft);
});

it('send() exige e-mail de todos no envio automático e aceita sem e-mail no manual', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana')]);
    $svc = app(EnvelopeService::class);

    expect(fn () => $svc->send($e->refresh()))->toThrow(FlowException::class, 'e-mail');
    expect($e->fresh()->status)->toBe(EnvelopeStatus::Draft);

    $e->update(['notify_channels' => ['manual']]);
    $svc->send($e->refresh());
    expect($e->fresh()->status)->toBe(EnvelopeStatus::Sent);
});

it('o detalhe do rascunho do assistente leva de volta ao assistente em vez de liberar às cegas', function () {
    $u = wzOwner();
    $wizard = wzDraft($u, 'Do assistente');
    $api = wzDraft($u, 'Da API');
    $api->update(['wizard_step' => null]);

    $this->actingAs($u)->get("/documentos/{$wizard->id}")->assertOk()->assertSee('Continuar no assistente')->assertSee("/documentos/{$wizard->id}/passo/2", false)->assertDontSee('Liberar para assinatura');
    $this->actingAs($u)->get("/documentos/{$api->id}")->assertOk()->assertSee('Liberar para assinatura')->assertDontSee('Continuar no assistente');
});

// ------------------------------------------------------------------ I2: código por e-mail exige e-mail

it('com código por e-mail ligado todo signatário precisa de e-mail, mesmo no envio manual', function () {
    $u = wzOwner();
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'never']);
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('João')], false, \App\Enums\SigningOrder::Parallel, ['otp' => true]);
    app(EnvelopeService::class)->syncFields($e->refresh(), []);
    $joao = $e->signers()->first();

    $this->actingAs($u)->post("/documentos/{$e->id}/finalizar", ['auto' => 0])->assertSessionHasErrors("emails.{$joao->id}");
    expect($e->fresh()->status)->toBe(EnvelopeStatus::Draft)->and(UsageEntry::count())->toBe(0);

    $e->update(['notify_channels' => ['manual']]);
    expect(fn () => app(EnvelopeService::class)->send($e->refresh()))->toThrow(FlowException::class, 'código');
});

// ------------------------------------------------------------------ I3: trocar o PDF com segurança

it('trocar o PDF com falha no meio mantém o arquivo antigo, apaga o novo e não mexe no armazenamento', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $oldPath = $e->document->storage_path;
    $before = $u->tenant->fresh()->storage_bytes;
    $filesBefore = Storage::disk('local')->allFiles();

    Document::deleting(fn () => throw new RuntimeException('falha simulada'));
    expect(fn () => app(EnvelopeService::class)->replaceDocument($e->refresh(), WZ_PDF.'%novo', 'novo.pdf'))->toThrow(RuntimeException::class);
    Document::flushEventListeners();

    Storage::disk('local')->assertExists($oldPath);
    expect(Storage::disk('local')->allFiles())->toBe($filesBefore)->and($u->tenant->fresh()->storage_bytes)->toBe($before)->and($e->fresh()->document_id)->toBe($e->document_id);
});

it('trocar o PDF de um modelo em memória que já foi enviado em outra aba é recusado', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com')]);
    $stale = Envelope::query()->findOrFail($e->id);
    DB::table('envelopes')->where('id', $e->id)->update(['status' => EnvelopeStatus::Sent->value]);

    expect(fn () => app(EnvelopeService::class)->replaceDocument($stale, WZ_PDF.'%outro', 'outro.pdf'))->toThrow(FlowException::class, 'rascunho');
    expect($e->fresh()->document->name)->toBe('contrato.pdf');
});

// ------------------------------------------------------------------ I4: data limite vale até o fim do dia (Brasília)

it('a data limite expira no fim do dia escolhido, em Brasília', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 13:00:00', 'UTC')); // 10h em Brasília
    $u = wzOwner();
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'never']);
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com')]);
    app(EnvelopeService::class)->syncFields($e->refresh(), []);

    $this->actingAs($u)->post("/documentos/{$e->id}/finalizar", ['auto' => 1, 'limit' => 1, 'limit_date' => '2026-11-20'])->assertRedirect("/documentos/{$e->id}");

    $expected = Carbon::parse('2026-11-20 23:59:59', 'America/Sao_Paulo');
    expect($e->fresh()->expires_at->equalTo($expected))->toBeTrue();
});

it('perto da meia-noite de Brasília "amanhã" continua valendo como data limite', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 01:30:00', 'UTC')); // ainda é 16/11, 22h30 em Brasília
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com')]);
    app(EnvelopeService::class)->syncFields($e->refresh(), []);

    $this->actingAs($u)->put("/documentos/{$e->id}/passo/4", ['auto' => 1, 'limit' => 1, 'limit_date' => '2026-11-17'])->assertSessionHasNoErrors();
    $this->actingAs($u)->put("/documentos/{$e->id}/passo/4", ['auto' => 1, 'limit' => 1, 'limit_date' => '2026-11-16'])->assertSessionHasErrors('limit_date');
});

it('ao reabrir o passo 4 a data limite escolhida volta igual, sem deslocar', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 13:00:00', 'UTC'));
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com')]);
    app(EnvelopeService::class)->syncFields($e->refresh(), []);
    $this->actingAs($u)->put("/documentos/{$e->id}/passo/4", ['auto' => 1, 'limit' => 1, 'limit_date' => '2026-11-25'])->assertSessionHasNoErrors();

    Carbon::setTestNow(Carbon::parse('2026-11-18 13:00:00', 'UTC')); // dois dias depois
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/4")->assertOk()->assertSee('value="2026-11-25"', false);
});

// ------------------------------------------------------------------ M2: mexer no rascunho conta como atividade

it('reposicionar campos hoje impede o rascunho antigo de ir para a lixeira', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana')]);
    DB::table('envelopes')->where('id', $e->id)->update(['updated_at' => now()->subDays(20)]);

    app(EnvelopeService::class)->syncFields($e->refresh(), [wzField($e->signers()->first()->id)]);
    $this->artisan('documents:trash-stale-drafts')->expectsOutputToContain('0 documento(s)')->assertExitCode(0);

    expect($e->fresh()->isTrashed())->toBeFalse();
});
