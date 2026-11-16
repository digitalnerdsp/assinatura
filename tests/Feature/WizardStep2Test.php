<?php

declare(strict_types=1);

use App\Enums\SigningOrder;
use App\Models\Envelope;
use App\Models\TenantSigningSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function wzPolicy(User $u, string $policy): void
{
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => $policy]);
}

function wzSign(User $u): void
{
    $u->forceFill(['signature_type' => 'typed', 'signature_data' => 'Dono'])->save();
}

function wzPut2(User $u, Envelope $e, array $data)
{
    return test()->actingAs($u)->put("/documentos/{$e->id}/passo/2", $data);
}

function wzOptional(): array
{
    $u = wzOwner();
    wzPolicy($u, 'optional');

    return [$u, wzDraft($u)];
}

it('o passo 2 começa só com o nome e traz os atalhos', function () {
    [$u, $e] = wzOptional();
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/2")->assertOk()
        ->assertSee('Quem irá assinar?')->assertSee('Nome do signatário')->assertSee('+ Signatário(a)')->assertSee('Autenticação avançada')
        ->assertSee('Eu vou assinar esse documento')->assertSee('Contatos')->assertSee('Observadores')->assertSee(route('contacts.suggest'), false);
});

it('salva só com o nome (sem e-mail) e leva ao passo 3', function () {
    [$u, $e] = wzOptional();
    wzPut2($u, $e, ['sender_signs' => 0, 'people' => [['name' => 'Ana Souza', 'role' => 'signer']]])->assertRedirect("/documentos/{$e->id}/passo/3");

    $s = $e->signers()->first();
    expect($e->signers()->count())->toBe(1)->and($s->name)->toBe('Ana Souza')->and($s->email)->toBeNull()->and($e->fresh()->wizard_step)->toBe(2);
});

it('recusa linha com e-mail mas sem nome e e-mail inválido, e nada é gravado', function () {
    [$u, $e] = wzOptional();
    wzPut2($u, $e, ['sender_signs' => 0, 'people' => [['name' => '', 'email' => 'a@a.com', 'role' => 'signer']]])->assertSessionHasErrors('people.0.name');
    wzPut2($u, $e, ['sender_signs' => 0, 'people' => [['name' => 'Ana', 'email' => 'nao-e-email', 'role' => 'signer']]])->assertSessionHasErrors('people.0.email');
    expect($e->signers()->count())->toBe(0);
});

it('linha toda em branco é ignorada; sem ninguém e sem remetente o passo não fecha', function () {
    [$u, $e] = wzOptional();
    wzPut2($u, $e, ['sender_signs' => 0, 'people' => [['name' => '', 'role' => 'signer']]])->assertSessionHasErrors('people');
    expect($e->signers()->count())->toBe(0);

    wzSign($u);
    wzPut2($u, $e, ['sender_signs' => 1, 'people' => [['name' => '', 'role' => 'signer']]])->assertRedirect("/documentos/{$e->id}/passo/3");
    expect($e->signers()->count())->toBe(1)->and($e->signers()->first()->is_sender)->toBeTrue();
});

it('política opcional: o remetente entra quando o switch liga e sai quando desliga', function () {
    [$u, $e] = wzOptional();
    wzSign($u);
    wzPut2($u, $e, ['sender_signs' => 1, 'people' => [['name' => 'Ana', 'role' => 'signer']]])->assertRedirect();
    expect($e->signers()->where('is_sender', true)->count())->toBe(1);

    $ana = $e->signers()->where('is_sender', false)->first();
    wzPut2($u, $e, ['sender_signs' => 0, 'people' => [['id' => $ana->id, 'name' => 'Ana', 'role' => 'signer']]])->assertRedirect();
    expect($e->signers()->where('is_sender', true)->count())->toBe(0);
});

it('política sempre: força o remetente e exige a assinatura salva em Meu perfil', function () {
    $u = wzOwner();
    wzPolicy($u, 'always');
    $e = wzDraft($u);

    $page = $this->actingAs($u)->get("/documentos/{$e->id}/passo/2")->assertOk();
    $page->assertSee('name="sender_signs" value="1"', false);

    wzPut2($u, $e, ['sender_signs' => 0, 'people' => [['name' => 'Ana', 'role' => 'signer']]])->assertSessionHasErrors('sender');
    wzPut2($u, $e, ['sender_signs' => 1, 'people' => [['name' => 'Ana', 'role' => 'signer']]])->assertSessionHasErrors('sender');
    expect($e->signers()->count())->toBe(0);

    wzSign($u);
    wzPut2($u, $e, ['sender_signs' => 1, 'people' => [['name' => 'Ana', 'role' => 'signer']]])->assertRedirect();
    expect($e->signers()->where('is_sender', true)->count())->toBe(1);
});

it('política nunca: o switch não aparece e pedir o remetente é recusado', function () {
    $u = wzOwner();
    wzPolicy($u, 'never');
    $e = wzDraft($u);

    $this->actingAs($u)->get("/documentos/{$e->id}/passo/2")->assertOk()->assertDontSee('Eu vou assinar esse documento');
    wzPut2($u, $e, ['sender_signs' => 1, 'people' => [['name' => 'Ana', 'role' => 'signer']]])->assertSessionHasErrors('sender');
});

it('guarda testemunha, ordem e verificações', function () {
    [$u, $e] = wzOptional();
    wzPut2($u, $e, [
        'sender_signs' => 0, 'signing_order' => 'sequential', 'require_otp' => 1, 'require_selfie' => 1,
        'people' => [['name' => 'Ana', 'email' => 'ana@x.com', 'role' => 'signer'], ['name' => 'Tomás', 'role' => 'witness']],
    ])->assertRedirect();

    $e->refresh();
    expect($e->signing_order)->toBe(SigningOrder::Sequential)->and($e->require_otp)->toBeTrue()->and($e->require_selfie)->toBeTrue()->and($e->require_document)->toBeFalse()
        ->and($e->signers()->where('role', 'witness')->count())->toBe(1);
});

it('reenviar com os ids mantém os signatários e os campos e remover apaga', function () {
    [$u, $e] = wzOptional();
    wzPut2($u, $e, ['sender_signs' => 0, 'people' => [['name' => 'Ana', 'role' => 'signer'], ['name' => 'Bia', 'role' => 'signer']]])->assertRedirect();
    [$ana, $bia] = $e->signers()->get()->all();
    app(\App\Envelopes\EnvelopeService::class)->syncFields($e->refresh(), [wzField($ana->id), wzField($bia->id)]);

    wzPut2($u, $e, ['sender_signs' => 0, 'people' => [['id' => $ana->id, 'name' => 'Ana Maria', 'role' => 'signer']]])->assertRedirect();

    expect($e->signers()->count())->toBe(1)->and($e->signers()->first()->id)->toBe($ana->id)->and($e->signers()->first()->name)->toBe('Ana Maria')
        ->and($e->signatureFields()->count())->toBe(1)->and($e->fresh()->wizard_step)->toBe(3);
});

it('ids de outro documento não são aproveitados e há limite de 20 pessoas', function () {
    [$u, $e] = wzOptional();
    $other = wzDraft($u, 'Outro');
    wzSync($other, $u, [wzPerson('Zé')]);
    $foreign = $other->signers()->first();

    wzPut2($u, $e, ['sender_signs' => 0, 'people' => [['id' => $foreign->id, 'name' => 'Intruso', 'role' => 'signer']]])->assertRedirect();
    expect($e->signers()->first()->id)->not->toBe($foreign->id)->and($foreign->fresh()->name)->toBe('Zé');

    $many = array_map(fn ($i) => ['name' => "P{$i}", 'role' => 'signer'], range(1, 21));
    wzPut2($u, $e, ['sender_signs' => 0, 'people' => $many])->assertSessionHasErrors('people');
});

it('ao voltar ao passo 2 os dados salvos aparecem', function () {
    [$u, $e] = wzOptional();
    wzPut2($u, $e, ['sender_signs' => 0, 'people' => [['name' => 'Ana Souza', 'email' => 'ana@x.com', 'phone' => '11999998888', 'role' => 'signer']]])->assertRedirect();
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/2")->assertOk()->assertSee('Ana Souza')->assertSee('ana@x.com');
});
