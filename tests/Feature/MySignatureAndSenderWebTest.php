<?php

declare(strict_types=1);

use App\Mail\SignatureRequestedMail;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\TenantSigningSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function sigRegister(string $email = 'dono@acme.com', string $company = 'ACME'): User
{
    test()->post('/cadastro', [
        'company' => $company, 'name' => 'Dono da Empresa', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

function sigPng(int $w = 200, int $h = 80): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    ob_start();
    imagepng($im);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

function sigPolicy(User $user, string $policy): void
{
    TenantSigningSetting::updateOrCreate(['tenant_id' => $user->tenant_id], ['sender_policy' => $policy]);
}

function sigWithSaved(User $user): void
{
    $user->forceFill(['signature_type' => 'typed', 'signature_data' => 'Dono da Empresa'])->save();
    test()->actingAs($user->fresh()); // o guard guarda o usuário da requisição de cadastro: recarrega
}

function sigStore(array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->post('/envelopes', $extra + [
        'title' => 'Contrato', 'signing_order' => 'sequential',
        'document' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"),
        'signers' => [['name' => 'Parte', 'email' => 'parte@ex.com']],
    ]);
}

it('salva a assinatura digitada e a desenhada (PNG) e remove', function () {
    $user = sigRegister();

    $this->put('/perfil/assinatura', ['type' => 'typed', 'data' => 'Fulano de Tal'])->assertRedirect();
    expect($user->fresh()->signature_type)->toBe('typed')->and($user->fresh()->hasSignature())->toBeTrue();

    $this->put('/perfil/assinatura', ['type' => 'drawn', 'data' => sigPng()])->assertRedirect();
    expect($user->fresh()->signature_type)->toBe('drawn')->and($user->fresh()->signature_data)->toStartWith('data:image/png;base64,');

    $this->delete('/perfil/assinatura')->assertRedirect();
    expect($user->fresh()->signature_data)->toBeNull()->and($user->fresh()->hasSignature())->toBeFalse();
});

it('recusa PNG corrompido, outro tipo de imagem, imagem gigante e nome grande demais', function (string $type, string $data) {
    sigRegister();

    $this->from('/perfil')->put('/perfil/assinatura', ['type' => $type, 'data' => $data])->assertSessionHasErrors('data');
})->with([
    'png corrompido' => ['drawn', 'data:image/png;base64,AAAA'],
    'não é data url' => ['drawn', 'não sou uma imagem'],
    'jpeg disfarçado' => ['drawn', 'data:image/png;base64,'.'/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='],
    'nome grande' => ['typed', str_repeat('a', 81)],
]);

it('recusa PNG com dimensões enormes', function () {
    sigRegister();

    $this->from('/perfil')->put('/perfil/assinatura', ['type' => 'drawn', 'data' => sigPng(1700, 900)])->assertSessionHasErrors('data');
});

it('a assinatura de um usuário não aparece para outra empresa', function () {
    $a = sigRegister('a@acme.com', 'ACME');
    $this->put('/perfil/assinatura', ['type' => 'typed', 'data' => 'ASSINATURA-SECRETA-DA-A']);
    auth()->logout();
    sigRegister('b@beta.com', 'Beta');

    $this->get('/perfil')->assertOk()->assertDontSee('ASSINATURA-SECRETA-DA-A');
    $this->get("/perfil/foto/{$a->id}")->assertNotFound(); // e nenhuma rota de perfil entrega dados de outro usuário
});

it('o formulário mostra a linha Eu (remetente) fixa quando a política é always', function () {
    $user = sigRegister();
    sigWithSaved($user);

    $this->get('/envelopes/novo')->assertOk()
        ->assertSee('Eu (remetente)')->assertSee('Dono da Empresa')
        ->assertSee('name="sender_signs" value="1"', false)->assertDontSee('type="checkbox" name="sender_signs"', false);
});

it('com optional vira caixa marcada e com never não aparece', function () {
    $user = sigRegister();
    sigWithSaved($user);

    sigPolicy($user, 'optional');
    $this->get('/envelopes/novo')->assertOk()->assertSee('type="checkbox" name="sender_signs"', false)->assertSee('checked', false);

    sigPolicy($user, 'never');
    $this->get('/envelopes/novo')->assertOk()->assertDontSee('id="sender-row"', false);
});

it('sem Minha assinatura salva a criação é recusada com link para o perfil', function () {
    sigRegister();
    Storage::fake('local');

    sigStore()->assertSessionHasErrors('sender');

    expect(Envelope::count())->toBe(0);
    $this->get('/envelopes/novo')->assertSee('Crie a sua assinatura em')->assertSee('#minha-assinatura', false);
});

it('criar e liberar assina na hora, sem avisar o remetente, e convida os demais', function () {
    $user = sigRegister();
    sigWithSaved($user);
    Storage::fake('local');
    Queue::fake();
    Mail::fake();
    sigStore(['sender_signs' => 1])->assertSessionHasNoErrors()->assertRedirect();
    $envelope = Envelope::firstOrFail();
    $this->post("/envelopes/{$envelope->id}/enviar")->assertRedirect();

    $sender = $envelope->signers()->where('is_sender', true)->firstOrFail();
    expect($sender->status->value)->toBe('signed')->and($sender->signed_via)->toBe('session')
        ->and($sender->email)->toBe('dono@acme.com')
        ->and($sender->signature->data)->toBe('Dono da Empresa');
    expect($envelope->fresh()->status->value)->toBe('in_progress');

    Mail::assertSent(SignatureRequestedMail::class, 1);
    Mail::assertSent(SignatureRequestedMail::class, fn ($m) => $m->hasTo('parte@ex.com'));
    Mail::assertNotSent(SignatureRequestedMail::class, fn ($m) => $m->hasTo('dono@acme.com'));
});

it('quem libera é o remetente: o rascunho de um colega assina com a assinatura de quem enviou', function () {
    $dono = sigRegister('dono@acme.com');
    sigWithSaved($dono);
    Storage::fake('local');
    Queue::fake();
    Mail::fake();
    sigStore(['sender_signs' => 1]);
    $envelope = Envelope::firstOrFail();

    $colega = User::create(['name' => 'Colega', 'email' => 'colega@acme.com', 'password' => 'senha-muito-forte', 'tenant_id' => $dono->tenant_id, 'role' => 'member']);
    $colega->forceFill(['signature_type' => 'typed', 'signature_data' => 'Colega Assina'])->save();
    auth()->logout();
    $this->actingAs($colega);

    $this->post("/envelopes/{$envelope->id}/enviar")->assertRedirect();

    $sender = $envelope->signers()->where('is_sender', true)->firstOrFail();
    expect($sender->name)->toBe('Colega')->and($sender->email)->toBe('colega@acme.com')
        ->and($sender->signature->data)->toBe('Colega Assina');
});

it('com política optional e a caixa desmarcada não cria remetente', function () {
    $user = sigRegister();
    sigPolicy($user, 'optional');
    Storage::fake('local');

    sigStore()->assertRedirect();

    expect(Envelope::firstOrFail()->signers()->where('is_sender', true)->count())->toBe(0);
});

it('o posicionamento aceita o remetente', function () {
    $user = sigRegister();
    sigWithSaved($user);
    Storage::fake('local');

    sigStore(['sender_signs' => 1, 'signature_fields' => [
        ['signer' => 'sender', 'page' => 1, 'x' => 0.1, 'y' => 0.8],
        ['signer' => 0, 'page' => 1, 'x' => 0.5, 'y' => 0.8],
    ]])->assertRedirect();

    $envelope = Envelope::firstOrFail();
    $sender = $envelope->signers()->where('is_sender', true)->firstOrFail();
    expect($envelope->signatureFields()->where('signer_id', $sender->id)->count())->toBe(1)
        ->and($envelope->signatureFields()->count())->toBe(2);
});

it('envio manual não avisa ninguém por e-mail, nem o próximo da fila', function () {
    $user = sigRegister();
    sigWithSaved($user);
    Storage::fake('local');
    Queue::fake();
    Mail::fake();
    sigStore(['sender_signs' => 1]);
    $envelope = Envelope::firstOrFail();

    $this->post("/envelopes/{$envelope->id}/enviar", ['skip_email' => 1])->assertRedirect();

    Mail::assertNothingSent();
    expect($envelope->signers()->where('is_sender', true)->firstOrFail()->status->value)->toBe('signed');
});

it('sem assinatura salva na hora de liberar, o envio é recusado e nada muda', function () {
    $user = sigRegister();
    sigWithSaved($user);
    Storage::fake('local');
    sigStore(['sender_signs' => 1]);
    $envelope = Envelope::firstOrFail();
    $user->forceFill(['signature_type' => null, 'signature_data' => null])->save();
    $this->actingAs($user->fresh());

    $this->from('/envelopes')->post("/envelopes/{$envelope->id}/enviar")->assertSessionHasErrors('envelope');

    expect($envelope->fresh()->status->value)->toBe('draft');
});
