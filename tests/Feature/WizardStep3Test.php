<?php

declare(strict_types=1);

use App\Models\Envelope;
use App\Models\TenantSigningSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

/** @return array{0:User,1:Envelope,2:\App\Models\Signer} rascunho no passo 2 concluído, com a Ana como signatária */
function wzAtStep3(): array
{
    $u = wzOwner();
    TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'optional']);
    $e = wzDraft($u);
    wzSync($e, $u, [wzPerson('Ana Souza', 'ana@x.com')]);

    return [$u, $e->refresh(), $e->signers()->first()];
}

function wzF(string $signerId, array $over = []): array
{
    return $over + ['signer_id' => $signerId, 'type' => 'signature', 'page' => 1, 'x' => 0.2, 'y' => 0.3, 'width' => 0.3, 'height' => 0.07];
}

function wzPut3(User $u, Envelope $e, array $data)
{
    return test()->actingAs($u)->put("/documentos/{$e->id}/passo/3", $data);
}

it('o passo 3 mostra o visualizador, os signatários e os botões', function () {
    [$u, $e, $ana] = wzAtStep3();
    $res = $this->actingAs($u)->get("/documentos/{$e->id}/passo/3")->assertOk();

    $res->assertSee('Posicione as assinaturas no documento')->assertSee('(Opcional)')->assertSee('Editar documento')->assertSee('Continuar sem posicionar')->assertSee('Salvar e continuar')
        ->assertSee('Ana Souza')->assertSee(route('envelopes.original', $e), false)->assertSee('Clique ou toque');
});

it('o passo 3 só abre depois do passo 2', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/3")->assertRedirect("/documentos/{$e->id}/passo/2");
});

it('salva os campos com os signatários reais e leva ao passo 4', function () {
    [$u, $e, $ana] = wzAtStep3();
    wzPut3($u, $e, ['fields' => [wzF($ana->id), wzF($ana->id, ['page' => 2, 'x' => 0.5, 'y' => 0.6])]])->assertRedirect("/documentos/{$e->id}/passo/4");

    $fields = $e->signatureFields()->orderBy('page')->get();
    expect($fields->count())->toBe(2)->and($fields[0]->signer_id)->toBe($ana->id)->and($fields[0]->type)->toBe('signature')->and($fields[1]->page)->toBe(2)
        ->and((float) $fields[1]->x)->toBe(0.5)->and($e->fresh()->wizard_step)->toBe(3);
});

it('salvar de novo substitui o conjunto e ao voltar os campos aparecem', function () {
    [$u, $e, $ana] = wzAtStep3();
    wzPut3($u, $e, ['fields' => [wzF($ana->id), wzF($ana->id, ['page' => 2])]])->assertRedirect();
    wzPut3($u, $e, ['fields' => [wzF($ana->id, ['page' => 3])]])->assertRedirect();

    expect($e->signatureFields()->count())->toBe(1)->and($e->signatureFields()->first()->page)->toBe(3);
    $html = $this->actingAs($u)->get("/documentos/{$e->id}/passo/3")->assertOk()->getContent();
    expect($html)->toContain('Salvar alterações');
});

it('continuar sem posicionar exige o aviso (skipped) e avança sem campos', function () {
    [$u, $e, $ana] = wzAtStep3();
    wzPut3($u, $e, [])->assertSessionHasErrors('fields');
    expect($e->fresh()->wizard_step)->toBe(2);

    wzPut3($u, $e, ['skipped' => 1])->assertRedirect("/documentos/{$e->id}/passo/4");
    expect($e->signatureFields()->count())->toBe(0)->and($e->fresh()->wizard_step)->toBe(3);
});

it('recusa campos inválidos e não grava nada', function () {
    [$u, $e, $ana] = wzAtStep3();
    $other = wzDraft($u, 'Outro');
    wzSync($other, $u, [wzPerson('Zé')]);
    $foreign = $other->signers()->first();
    wzPut3($u, $e, ['fields' => [wzF($ana->id)]])->assertRedirect();

    wzPut3($u, $e, ['fields' => [wzF($ana->id, ['x' => 1.2])]])->assertSessionHasErrors('fields');
    wzPut3($u, $e, ['fields' => [wzF($ana->id, ['page' => 0])]])->assertSessionHasErrors('fields.0.page');
    wzPut3($u, $e, ['fields' => [wzF($ana->id, ['type' => 'carimbo'])]])->assertSessionHasErrors('fields');
    wzPut3($u, $e, ['fields' => [wzF($foreign->id)]])->assertSessionHasErrors('fields');
    wzPut3($u, $e, ['fields' => [wzF($ana->id, ['width' => 'abc'])]])->assertSessionHasErrors('fields.0.width');
    wzPut3($u, $e, ['fields' => array_fill(0, 61, wzF($ana->id))])->assertSessionHasErrors('fields');

    expect($e->signatureFields()->count())->toBe(1)->and($e->signatureFields()->first()->page)->toBe(1);
});

it('o PDF do passo 3 é servido só para a própria empresa', function () {
    [$u, $e] = wzAtStep3();
    $this->actingAs($u)->get(route('envelopes.original', $e))->assertOk();
    $this->actingAs(wzOwner('intruso@wz.test'))->get(route('envelopes.original', $e))->assertNotFound();
});
