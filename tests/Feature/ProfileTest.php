<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function profileRegister(string $email, string $company): User
{
    test()->post('/cadastro', [
        'company' => $company, 'name' => 'Dono '.$company, 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

it('troca nome e foto, recorta para quadrado e serve a imagem', function () {
    Storage::fake('local');
    $user = profileRegister('a@acme.com', 'ACME');

    $this->get('/perfil')->assertOk()->assertSee('Foto de perfil');

    $this->put('/perfil', ['name' => 'Novo Nome', 'photo' => UploadedFile::fake()->image('eu.png', 600, 300)])
        ->assertRedirect('/perfil');

    $user->refresh();
    expect($user->name)->toBe('Novo Nome')->and($user->avatar_path)->toStartWith('avatars/');
    Storage::disk('local')->assertExists($user->avatar_path);

    if (function_exists('imagecreatefromstring')) {
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($user->avatar_path));
        expect([$w, $h])->toBe([256, 256]);
    }

    $this->get($user->avatarUrl())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/painel')->assertSee($user->avatarUrl(), false);
});

it('rejeita arquivo que não é imagem e SVG', function () {
    Storage::fake('local');
    profileRegister('a@acme.com', 'ACME');

    $this->from('/perfil')->put('/perfil', ['name' => 'X', 'photo' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('photo');
    $this->from('/perfil')->put('/perfil', ['name' => 'X', 'photo' => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])
        ->assertSessionHasErrors('photo');
});

it('remove a foto e apaga o arquivo', function () {
    Storage::fake('local');
    $user = profileRegister('a@acme.com', 'ACME');
    $this->put('/perfil', ['name' => 'X', 'photo' => UploadedFile::fake()->image('eu.jpg', 300, 300)]);
    $path = $user->refresh()->avatar_path;

    $this->delete('/perfil/foto')->assertRedirect();

    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('a foto só é visível para o mesmo cliente ou para o próprio dono', function () {
    Storage::fake('local');
    $a = profileRegister('a@acme.com', 'ACME');
    $this->put('/perfil', ['name' => 'A', 'photo' => UploadedFile::fake()->image('eu.jpg', 300, 300)]);
    $url = $a->refresh()->avatarUrl();
    auth()->logout();

    profileRegister('b@outra.com', 'Outra');
    $this->get($url)->assertNotFound();

    auth()->logout();
    $this->get($url)->assertRedirect('/entrar');
});
