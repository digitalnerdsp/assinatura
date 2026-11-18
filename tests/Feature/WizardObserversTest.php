<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SigningService;
use App\Evidence\EvidenceLogger;
use App\Jobs\SealEnvelope;
use App\Mail\ObserverCopyMail;
use App\Mail\SignatureRequestedMail;
use App\Models\Envelope;
use App\Models\TenantSigningSetting;
use App\Models\User;
use App\Signing\Contracts\SigningProvider;
use App\Signing\Data\CertificateInfo;
use App\Signing\Data\SealedDocument;
use App\Signing\Data\SealRequest;
use App\Signing\ReportBuilder;
use App\Signing\SigningManager;
use App\Signing\Support\SealerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    Queue::fake([SealEnvelope::class]);
});

function wzObs(string $name, ?string $email = 'obs@x.com'): array
{
    return wzPerson($name, $email, 'observer');
}

/** @return array{0:User,1:Envelope} documento com a Ana (signatária) e dois observadores, pronto para enviar */
function wzWithObservers(): array
{
    $u = wzOwner();
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'never']);
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com'), wzObs('Obs Um', 'um@x.com'), wzObs('Obs Dois', 'dois@x.com')]);
    app(EnvelopeService::class)->syncFields($e->refresh(), []);

    return [$u, $e->refresh()];
}

// ------------------------------------------------------------------ regras do serviço

it('guarda observadores com o papel observer, depois dos demais, e limita a quantidade', function () {
    [$u, $e] = wzWithObservers();
    $roles = $e->signers()->orderBy('position')->pluck('role', 'name')->all();

    expect($roles)->toBe(['Ana' => 'signer', 'Obs Um' => 'observer', 'Obs Dois' => 'observer'])
        ->and($e->signers()->where('role', 'observer')->min('position'))->toBeGreaterThan($e->signers()->where('role', 'signer')->max('position'));

    config(['signing.max_observers' => 2]);
    $many = array_merge([wzPerson('Ana', 'ana@x.com')], array_map(fn ($i) => wzObs("O{$i}", "o{$i}@x.com"), [1, 2, 3]));
    expect(fn () => wzSync($e, $u, $many))->toThrow(FlowException::class, 'observadores');
});

it('só observador não basta: precisa de ao menos um signatário', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    expect(fn () => wzSync($e, $u, [wzObs('Só observo')]))->toThrow(FlowException::class, 'ao menos um');
});

it('o observador não recebe link, não conta para concluir e não pode ter campo', function () {
    [$u, $e] = wzWithObservers();
    $obs = $e->signers()->where('role', 'observer')->first();
    expect(fn () => app(EnvelopeService::class)->syncFields($e, [wzField($obs->id)]))->toThrow(FlowException::class, 'observador');

    $tokens = app(EnvelopeService::class)->send($e->refresh());
    expect($tokens)->toHaveCount(1)->and($obs->fresh()->token_hash)->toBeNull();

    app(SigningService::class)->sign($e->signers()->where('role', 'signer')->first(), 'typed', 'Ana', true);
    expect($e->fresh()->status)->toBe(EnvelopeStatus::Sealing);
});

it('o observador precisa de e-mail para receber a cópia, mesmo no envio manual', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com'), wzObs('Sem e-mail', null)]);
    $e->update(['notify_channels' => ['manual']]);

    expect(fn () => app(EnvelopeService::class)->assertSendable($e->refresh(), false))->toThrow(FlowException::class, 'observador');
    expect(fn () => app(EnvelopeService::class)->send($e->refresh()))->toThrow(FlowException::class, 'observador');
});

it('os convites vão só para quem assina', function () {
    [$u, $e] = wzWithObservers();
    $ana = $e->signers()->where('role', 'signer')->first();

    $this->actingAs($u)->post("/documentos/{$e->id}/finalizar", ['auto' => 1, 'emails' => [$ana->id => 'ana@x.com']])->assertRedirect("/documentos/{$e->id}");

    Mail::assertSent(SignatureRequestedMail::class, 1);
    Mail::assertSent(SignatureRequestedMail::class, fn ($m) => $m->hasTo('ana@x.com'));
});

// ------------------------------------------------------------------ cópia final, relatório e telas

it('ao concluir, cada observador recebe o PDF selado por e-mail e a trilha registra', function () {
    [$u, $e] = wzWithObservers();
    app(EnvelopeService::class)->send($e->refresh());
    app(SigningService::class)->sign($e->signers()->where('role', 'signer')->first(), 'typed', 'Ana', true);
    wzSealNow($e);

    Mail::assertSent(ObserverCopyMail::class, 2);
    Mail::assertSent(ObserverCopyMail::class, fn ($m) => $m->hasTo('um@x.com') && count($m->attachments()) === 1);
    Mail::assertSent(ObserverCopyMail::class, fn ($m) => $m->hasTo('dois@x.com'));
    expect($e->fresh()->status)->toBe(EnvelopeStatus::Completed)->and($e->evidenceEvents()->where('type', 'observer_copy_sent')->count())->toBe(2)
        ->and(app(EvidenceLogger::class)->isIntact($e->fresh()))->toBeTrue();
});

it('falha no e-mail do observador não atrapalha a conclusão', function () {
    [$u, $e] = wzWithObservers();
    app(EnvelopeService::class)->send($e->refresh());
    app(SigningService::class)->sign($e->signers()->where('role', 'signer')->first(), 'typed', 'Ana', true);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp fora do ar'));

    wzSealNow($e);

    expect($e->fresh()->status)->toBe(EnvelopeStatus::Completed)->and($e->evidenceEvents()->where('type', 'observer_copy_sent')->count())->toBe(0);
});

it('documento sem observadores não manda cópia nenhuma', function () {
    $u = wzOwner();
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'never']);
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com')]);
    app(EnvelopeService::class)->syncFields($e->refresh(), []);
    app(EnvelopeService::class)->send($e->refresh());
    app(SigningService::class)->sign($e->signers()->first(), 'typed', 'Ana', true);
    wzSealNow($e);

    Mail::assertNotSent(ObserverCopyMail::class);
});

it('o relatório e a verificação pública não listam observadores', function () {
    [$u, $e] = wzWithObservers();
    app(EnvelopeService::class)->send($e->refresh());
    app(SigningService::class)->sign($e->signers()->where('role', 'signer')->first(), 'typed', 'Ana', true);
    wzSealNow($e);

    $names = collect(app(ReportBuilder::class)->build($e->fresh())['signers'])->pluck('name')->all();
    expect($names)->toBe(['Ana']);

    $this->get('/verificar/'.$e->verification_code)->assertOk()->assertSee('Ana')->assertDontSee('Obs Um')->assertDontSee('um@x.com');
});

it('a lista conta só quem assina e o detalhe marca o observador sem link', function () {
    [$u, $e] = wzWithObservers();
    app(EnvelopeService::class)->send($e->refresh());

    $this->actingAs($u)->get('/documentos')->assertOk()->assertSee('data-label="Signatários">1<', false);
    $this->actingAs($u)->get("/documentos/{$e->id}")->assertOk()->assertSee('Observador')->assertSee('Obs Um')->assertDontSee('Link de Obs Um');
});

// ------------------------------------------------------------------ passo 2 e passo 3

it('o passo 2 tem a seção Observadores e não diz mais "em breve"', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/2")->assertOk()->assertSee('Observadores')->assertSee('recebem uma cópia')->assertDontSee('em breve');
});

it('salva observadores pelo passo 2, exige o e-mail e mostra de volta', function () {
    $u = wzOwner();
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'never']);
    $e = wzDraft($u);

    $this->actingAs($u)->put("/documentos/{$e->id}/passo/2", ['sender_signs' => 0, 'people' => [
        ['name' => 'Ana', 'role' => 'signer'], ['name' => 'Obs Sem E-mail', 'role' => 'observer'],
    ]])->assertSessionHasErrors('people.1.email');
    expect($e->signers()->count())->toBe(0);

    $this->actingAs($u)->put("/documentos/{$e->id}/passo/2", ['sender_signs' => 0, 'people' => [
        ['name' => 'Ana', 'role' => 'signer'], ['name' => 'Obs Um', 'email' => 'um@x.com', 'role' => 'observer'],
    ]])->assertRedirect("/documentos/{$e->id}/passo/3");

    expect($e->signers()->where('role', 'observer')->count())->toBe(1);
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/2")->assertOk()->assertSee('Obs Um')->assertSee('um@x.com');
});

it('o passo 3 não oferece observadores para receber campos', function () {
    [$u, $e] = wzWithObservers();
    $html = $this->actingAs($u)->get("/documentos/{$e->id}/passo/3")->assertOk()->getContent();

    expect($html)->toContain('Ana')->and($html)->not->toContain('Obs Um');
});

// ------------------------------------------------------------------ cópia assinada para quem assinou e para o remetente

function wzCompleted(): array
{
    [$u, $e] = wzWithObservers();
    app(EnvelopeService::class)->send($e->refresh());
    $ana = $e->signers()->where('role', 'signer')->first();
    $url = $ana->signUrl();
    app(SigningService::class)->sign($ana, 'typed', 'Ana', true);
    wzSealNow($e);

    return [$u, $e->fresh(), $ana, $url];
}

it('ao concluir, o signatário e o remetente também recebem o PDF selado por e-mail', function () {
    [$u, $e] = wzCompleted();

    Mail::assertSent(\App\Mail\SignedCopyMail::class, 2);
    Mail::assertSent(\App\Mail\SignedCopyMail::class, fn ($m) => $m->hasTo('ana@x.com') && count($m->attachments()) === 1);
    Mail::assertSent(\App\Mail\SignedCopyMail::class, fn ($m) => $m->hasTo($u->email) && count($m->attachments()) === 1);
    expect($e->evidenceEvents()->where('type', 'signed_copy_sent')->count())->toBe(2);
});

it('o signatário que já assinou vê a tela de conclusão com o botão de baixar quando o documento é selado', function () {
    [, $e, , $url] = wzCompleted();

    $this->followingRedirects()->get($url)->assertOk()->assertSee('Baixar documento assinado')->assertSee('Ver documento');
});

it('o link de download entrega o PDF selado e registra na trilha', function () {
    [, $e, , $url] = wzCompleted();

    $r = $this->get($url.'/assinado/baixar');
    $r->assertOk();
    expect($r->headers->get('content-type'))->toContain('application/pdf')
        ->and($r->headers->get('content-disposition'))->toContain('attachment')
        ->and($e->evidenceEvents()->where('type', 'signed_copy_downloaded')->count())->toBe(1);

    $this->get($url.'/assinado/baixar?ver=1')->assertOk();
});

it('antes do selamento não há o que baixar e a tela avisa que falta os demais', function () {
    [$u, $e] = wzWithObservers();
    app(EnvelopeService::class)->send($e->refresh());
    $ana = $e->signers()->where('role', 'signer')->first();
    $url = $ana->signUrl();
    app(SigningService::class)->sign($ana, 'typed', 'Ana', true);

    $this->followingRedirects()->get($url)->assertOk()->assertDontSee('Baixar documento assinado');
    $this->get($url.'/assinado/baixar')->assertNotFound();
});

it('quem não assinou não baixa o documento por esse caminho', function () {
    [$u, $e] = wzWithObservers();
    app(EnvelopeService::class)->send($e->refresh());
    $url = $e->signers()->where('role', 'signer')->first()->signUrl();

    $this->get($url.'/assinado/baixar')->assertNotFound();
});
