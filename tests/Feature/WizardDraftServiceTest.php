<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const WZ_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

function wzOwner(string $email = 'dono@wz.test'): User
{
    return User::create(['tenant_id' => Tenant::create(['name' => 'Empresa '.$email])->id, 'name' => 'Dono', 'email' => $email, 'password' => 'senha-muito-forte', 'role' => 'owner']);
}

function wzDraft(User $u, string $title = 'Contrato', string $pdf = WZ_PDF): Envelope
{
    return app(EnvelopeService::class)->createDraft($u->tenant, $u, $pdf, 'contrato.pdf', $title);
}

function wzPerson(string $name, ?string $email = null, string $role = 'signer', array $extra = []): array
{
    return ['name' => $name, 'email' => $email, 'role' => $role] + $extra;
}

function wzSync(Envelope $e, User $u, array $people, bool $sender = false, SigningOrder $order = SigningOrder::Parallel, array $verif = []): void
{
    app(EnvelopeService::class)->syncPeople($e, $u, $people, $sender, $order, $verif);
}

function wzField(string $signerId, array $over = []): array
{
    return $over + ['signer_id' => $signerId, 'type' => 'signature', 'page' => 1, 'x' => 0.2, 'y' => 0.3, 'width' => 0.3, 'height' => 0.07];
}

beforeEach(fn () => Storage::fake('local'));

it('cria o rascunho com o PDF, sem signatários e com a recusa desligada', function () {
    $u = wzOwner();
    $e = wzDraft($u);

    expect($e->status)->toBe(EnvelopeStatus::Draft)->and($e->wizard_step)->toBe(1)->and($e->allow_refuse)->toBeFalse()
        ->and($e->signers()->count())->toBe(0)->and($e->created_by)->toBe($u->id)->and($e->title)->toBe('Contrato');
    Storage::disk('local')->assertExists($e->document->storage_path);
    expect($u->tenant->fresh()->storage_bytes)->toBe(strlen(WZ_PDF))
        ->and($e->evidenceEvents()->first()->type)->toBe('document_created');
});

it('recusa arquivo que não é PDF e PDF acima do limite', function () {
    $u = wzOwner();
    expect(fn () => wzDraft($u, 'X', 'texto qualquer'))->toThrow(FlowException::class, 'PDF');
    config(['signing.max_pdf_mb' => 0]);
    expect(fn () => wzDraft($u))->toThrow(FlowException::class, 'excede');
});

it('só aceita pasta da própria empresa', function () {
    $u = wzOwner();
    $other = wzOwner('outro@wz.test');
    $mine = Folder::create(['tenant_id' => $u->tenant_id, 'name' => 'Minha']);
    $foreign = Folder::create(['tenant_id' => $other->tenant_id, 'name' => 'Alheia']);

    expect(app(EnvelopeService::class)->createDraft($u->tenant, $u, WZ_PDF, 'a.pdf', 'T', $mine->id)->folder_id)->toBe($mine->id);
    expect(fn () => app(EnvelopeService::class)->createDraft($u->tenant, $u, WZ_PDF, 'a.pdf', 'T', $foreign->id))->toThrow(FlowException::class, 'Pasta inválida');
});

it('trocar o PDF apaga o antigo, acerta o armazenamento e zera os campos', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana')]);
    app(EnvelopeService::class)->syncFields($e->refresh(), [wzField($e->signers()->first()->id)]);
    $oldPath = $e->document->storage_path;
    $oldDocId = $e->document_id;
    $new = WZ_PDF.str_repeat('%', 50);

    app(EnvelopeService::class)->replaceDocument($e->refresh(), $new, 'novo.pdf');

    $e->refresh();
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($e->document->storage_path);
    expect($e->document_id)->not->toBe($oldDocId)->and($e->document->name)->toBe('novo.pdf')->and($e->document->sha256)->toBe(hash('sha256', $new))
        ->and($e->signatureFields()->count())->toBe(0)->and($e->wizard_step)->toBe(1)
        ->and($u->tenant->fresh()->storage_bytes)->toBe(strlen($new))
        ->and(\App\Models\Document::query()->whereKey($oldDocId)->exists())->toBeFalse();
});

it('sincroniza as pessoas: mantém quem tem id, cria os novos e remove os ausentes com seus campos', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ANA@x.com'), wzPerson('Bia')]);
    [$ana, $bia] = $e->signers()->get()->all();
    expect($ana->email)->toBe('ana@x.com')->and($bia->email)->toBeNull()->and($e->refresh()->wizard_step)->toBe(2);
    app(EnvelopeService::class)->syncFields($e, [wzField($ana->id), wzField($bia->id, ['page' => 2])]);

    wzSync($e->refresh(), $u, [wzPerson('Ana Maria', 'ana@x.com', 'signer', ['id' => $ana->id]), wzPerson('Caio', 'caio@x.com')]);

    $ids = $e->signers()->pluck('name', 'id');
    expect($ids->count())->toBe(2)->and($ids[$ana->id])->toBe('Ana Maria')->and($ids->contains('Caio'))->toBeTrue()->and($ids->contains('Bia'))->toBeFalse()
        ->and($e->signatureFields()->count())->toBe(1)->and($e->signatureFields()->first()->signer_id)->toBe($ana->id);
});

it('ligar e desligar o remetente cria e remove o signatário remetente e seus campos', function () {
    $u = wzOwner();
    $e = wzDraft($u);

    wzSync($e, $u, [wzPerson('Ana')], true);
    $sender = $e->signers()->where('is_sender', true)->first();
    expect($sender)->not->toBeNull()->and($sender->position)->toBe(0)->and($sender->email)->toBe($u->email)->and($sender->name)->toBe($u->name);
    app(EnvelopeService::class)->syncFields($e->refresh(), [wzField($sender->id)]);

    wzSync($e->refresh(), $u, [wzPerson('Ana', null, 'signer', ['id' => $e->signers()->where('is_sender', false)->first()->id])], false);
    expect($e->signers()->where('is_sender', true)->count())->toBe(0)->and($e->signatureFields()->count())->toBe(0);
});

it('aplica ordem, verificações e testemunha por último e recusa papel inválido', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Test', 't@x.com', 'witness'), wzPerson('Ana', 'a@x.com')], false, SigningOrder::Sequential, ['otp' => true, 'geolocation' => true]);

    $e->refresh();
    expect($e->signing_order)->toBe(SigningOrder::Sequential)->and($e->require_otp)->toBeTrue()->and($e->require_geolocation)->toBeTrue()->and($e->require_selfie)->toBeFalse()
        ->and($e->required_level)->toBe('advanced');
    $signers = $e->signers()->get()->keyBy('name');
    expect($signers['Test']->position)->toBeGreaterThan($signers['Ana']->position);

    expect(fn () => wzSync($e, $u, [wzPerson('X', null, 'approver')]))->toThrow(FlowException::class, 'Papel inválido');
});

it('sem nenhum participante o passo 2 não fecha', function () {
    $u = wzOwner();
    expect(fn () => wzSync(wzDraft($u), $u, []))->toThrow(FlowException::class, 'ao menos um');
});

it('valida os campos posicionados', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana')]);
    $ana = $e->signers()->first();
    $o = wzOwner('o2@wz.test');
    $other = wzDraft($o);
    wzSync($other, $o, [wzPerson('Zé')]);
    $svc = app(EnvelopeService::class);

    $svc->syncFields($e->refresh(), [wzField($ana->id), wzField($ana->id, ['page' => 2, 'x' => 0.5])]);
    expect($e->signatureFields()->count())->toBe(2)->and($e->refresh()->wizard_step)->toBe(3);
    $svc->syncFields($e, [wzField($ana->id)]);
    expect($e->signatureFields()->count())->toBe(1);

    expect(fn () => $svc->syncFields($e, [wzField($other->signers()->first()->id)]))->toThrow(FlowException::class, 'signatário');
    expect(fn () => $svc->syncFields($e, [wzField($ana->id, ['type' => 'text'])]))->toThrow(FlowException::class, 'tipo');
    expect(fn () => $svc->syncFields($e, [wzField($ana->id, ['x' => 1.2])]))->toThrow(FlowException::class, 'posição');
    expect(fn () => $svc->syncFields($e, [wzField($ana->id, ['page' => 0])]))->toThrow(FlowException::class, 'página');
    expect(fn () => $svc->syncFields($e, array_fill(0, 61, wzField($ana->id))))->toThrow(FlowException::class, '60');
    expect($e->signatureFields()->count())->toBe(1);
});

it('grava as opções de envio', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $tag = Tag::create(['tenant_id' => $u->tenant_id, 'name' => 'Urgente', 'color' => 'danger']);
    $foreign = Tag::create(['tenant_id' => wzOwner('t2@wz.test')->tenant_id, 'name' => 'Alheia', 'color' => 'info']);

    app(EnvelopeService::class)->applyDelivery($e, [
        'message' => 'Olá', 'reminder_every_days' => 5, 'validity_days' => 20, 'channels' => ['email', 'sms', 'pombo'], 'allow_refuse' => true, 'tags' => [$tag->id, $foreign->id],
    ]);

    $e->refresh();
    expect($e->message)->toBe('Olá')->and($e->reminder_every_days)->toBe(5)->and($e->validity_days)->toBe(20)->and($e->notify_channels)->toBe(['email', 'sms'])
        ->and($e->allow_refuse)->toBeTrue()->and($e->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
    expect(fn () => app(EnvelopeService::class)->applyDelivery($e, ['message' => str_repeat('a', 501)]))->toThrow(FlowException::class, '500');
    expect(fn () => app(EnvelopeService::class)->applyDelivery($e, ['validity_days' => 400]))->toThrow(FlowException::class, 'prazo');
});

it('confere o que falta antes de enviar', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $svc = app(EnvelopeService::class);
    expect(fn () => $svc->assertSendable($e, true))->toThrow(FlowException::class, 'ao menos um');

    wzSync($e, $u, [wzPerson('Ana')]);
    expect(fn () => $svc->assertSendable($e->refresh(), true))->toThrow(FlowException::class, 'e-mail');
    $svc->assertSendable($e, false);

    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com', 'signer', ['id' => $e->signers()->first()->id])]);
    $svc->assertSendable($e->refresh(), true);

    $solo = wzDraft($u);
    wzSync($solo, $u, [], true);
    $svc->assertSendable($solo->refresh(), true); // só o remetente assina: sem e-mail para cobrar
    expect(true)->toBeTrue();
});

it('depois do envio nenhuma operação de rascunho funciona', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana', 'ana@x.com')]);
    $e->update(['status' => EnvelopeStatus::Sent]);
    $svc = app(EnvelopeService::class);
    $e->refresh();

    expect(fn () => $svc->replaceDocument($e, WZ_PDF, 'a.pdf'))->toThrow(FlowException::class, 'rascunho')
        ->and(fn () => wzSync($e, $u, [wzPerson('X')]))->toThrow(FlowException::class, 'rascunho')
        ->and(fn () => $svc->syncFields($e, []))->toThrow(FlowException::class, 'rascunho')
        ->and(fn () => $svc->applyDelivery($e, ['message' => 'x']))->toThrow(FlowException::class, 'rascunho');
});
