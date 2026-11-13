<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function folderWebRegister(string $email = 'dono@acme.com', string $company = 'ACME'): User
{
    test()->post('/cadastro', [
        'company' => $company, 'name' => 'Dono', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

function folderWebEnvelope(User $user, ?Folder $folder, string $status): Envelope
{
    $doc = Document::create(['tenant_id' => $user->tenant_id, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);

    return Envelope::create([
        'tenant_id' => $user->tenant_id, 'document_id' => $doc->id, 'title' => 'Doc '.$status, 'status' => $status,
        'verification_code' => strtoupper(Str::random(12)), 'folder_id' => $folder?->id,
    ]);
}

it('cria pasta na raiz e subpasta', function () {
    $user = folderWebRegister();

    $this->post('/organizacao/pastas', ['name' => 'Contratos'])->assertSessionHasNoErrors()->assertSessionHas('status', 'Pasta criada.');
    $root = Folder::firstOrFail();
    expect($root->parent_id)->toBeNull()->and($root->tenant_id)->toBe($user->tenant_id);

    $this->post('/organizacao/pastas', ['name' => '2026', 'parent_id' => $root->id])->assertSessionHasNoErrors();
    expect(Folder::where('name', '2026')->firstOrFail()->parent_id)->toBe($root->id);
});

it('recusa nome duplicado na mesma pasta e aceita em outra', function () {
    folderWebRegister();
    $this->post('/organizacao/pastas', ['name' => 'Contratos']);
    $root = Folder::firstOrFail();

    $this->post('/organizacao/pastas', ['name' => 'contratos'])->assertSessionHasErrors('name');
    $this->post('/organizacao/pastas', ['name' => 'Contratos', 'parent_id' => $root->id])->assertSessionHasNoErrors();
    expect(Folder::count())->toBe(2);
});

it('recusa o 5º nível com mensagem em português', function () {
    folderWebRegister();
    $parent = null;
    foreach (['N1', 'N2', 'N3', 'N4'] as $name) {
        $this->post('/organizacao/pastas', ['name' => $name, 'parent_id' => $parent?->id])->assertSessionHasNoErrors();
        $parent = Folder::where('name', $name)->firstOrFail();
    }

    $response = $this->post('/organizacao/pastas', ['name' => 'N5', 'parent_id' => $parent->id]);

    $response->assertSessionHasErrors('parent_id');
    expect(session('errors')->first('parent_id'))->toContain('níveis')->and(Folder::count())->toBe(4);
});

it('renomeia e move pasta, recusando ciclo', function () {
    folderWebRegister();
    $this->post('/organizacao/pastas', ['name' => 'A']);
    $a = Folder::where('name', 'A')->firstOrFail();
    $this->post('/organizacao/pastas', ['name' => 'B', 'parent_id' => $a->id]);
    $b = Folder::where('name', 'B')->firstOrFail();

    $this->put("/organizacao/pastas/{$a->id}", ['name' => 'Alfa'])->assertSessionHasNoErrors()->assertSessionHas('status', 'Pasta renomeada.');
    expect($a->fresh()->name)->toBe('Alfa');

    $this->post("/organizacao/pastas/{$a->id}/mover", ['parent_id' => $b->id])->assertSessionHasErrors('parent_id'); // ciclo
    $this->post("/organizacao/pastas/{$b->id}/mover", ['parent_id' => ''])->assertSessionHasNoErrors()->assertSessionHas('status', 'Pasta movida.');
    expect($b->fresh()->parent_id)->toBeNull();
});

it('o impacto devolve JSON com os números', function () {
    $user = folderWebRegister();
    $this->post('/organizacao/pastas', ['name' => 'Pai']);
    $pai = Folder::firstOrFail();
    $this->post('/organizacao/pastas', ['name' => 'Filha', 'parent_id' => $pai->id]);
    $filha = Folder::where('name', 'Filha')->firstOrFail();
    folderWebEnvelope($user, $filha, 'draft');
    folderWebEnvelope($user, $filha, 'completed');
    folderWebEnvelope($user, $filha, 'sent');

    $this->getJson("/organizacao/pastas/{$pai->id}/impacto")->assertOk()
        ->assertExactJson(['trash' => 1, 'keep' => 2, 'folders' => 1, 'destination' => null]);
});

it('apagar remove a pasta, aplica a regra e a mensagem cita os números', function () {
    $user = folderWebRegister();
    $this->post('/organizacao/pastas', ['name' => 'Pai']);
    $pai = Folder::firstOrFail();
    folderWebEnvelope($user, $pai, 'draft');
    folderWebEnvelope($user, $pai, 'draft');
    $kept = folderWebEnvelope($user, $pai, 'completed');

    $this->delete("/organizacao/pastas/{$pai->id}")->assertSessionHasNoErrors();

    expect(Folder::count())->toBe(0)
        ->and(Envelope::whereNotNull('trashed_at')->count())->toBe(2)
        ->and($kept->fresh()->folder_id)->toBeNull()->and($kept->fresh()->trashed_at)->toBeNull();
    $message = session('status');
    expect($message)->toContain('2 ')->toContain('lixeira')->toContain('1 ');
});

it('ações em pasta de outra empresa devolvem 404 e não alteram nada', function () {
    $alheia = Folder::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Alheia']);
    folderWebRegister();

    $this->put("/organizacao/pastas/{$alheia->id}", ['name' => 'Invadida'])->assertNotFound();
    $this->post("/organizacao/pastas/{$alheia->id}/mover", ['parent_id' => ''])->assertNotFound();
    $this->getJson("/organizacao/pastas/{$alheia->id}/impacto")->assertNotFound();
    $this->delete("/organizacao/pastas/{$alheia->id}")->assertNotFound();
    expect($alheia->fresh()->name)->toBe('Alheia');

    $this->post('/organizacao/pastas', ['name' => 'Nova', 'parent_id' => $alheia->id])->assertSessionHasErrors('parent_id');
    expect(Folder::where('name', 'Nova')->count())->toBe(0);

    $this->post('/organizacao/pastas', ['name' => 'Minha']);
    $minha = Folder::where('name', 'Minha')->firstOrFail();
    $this->post("/organizacao/pastas/{$minha->id}/mover", ['parent_id' => $alheia->id])->assertSessionHasErrors('parent_id');
    expect($minha->fresh()->parent_id)->toBeNull();
});

it('GET /organizacao redireciona para a página Pastas e etiquetas', function () {
    folderWebRegister();

    $this->get('/organizacao')->assertStatus(301)->assertRedirect('/documentos/pastas');
});

it('Pastas e etiquetas fica no menu Documentos e não em Configurações', function () {
    folderWebRegister();
    $html = $this->get('/painel')->assertOk()->getContent();

    preg_match('/Documentos<\/div><\/a>\s*<ul class="mm-collapse[^"]*">(.*?)<\/ul>/s', $html, $docs);
    preg_match('/Configurações<\/div><\/a>\s*<ul class="mm-collapse[^"]*">(.*?)<\/ul>/s', $html, $cfg);
    expect($docs[1] ?? '')->toContain('Pastas e etiquetas')->and($cfg[1] ?? '')->not->toContain('Pastas e etiquetas');
});
