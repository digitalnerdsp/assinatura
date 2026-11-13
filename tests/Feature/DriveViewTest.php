<?php

declare(strict_types=1);

use App\Folders\FolderService;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function driveRegister(string $email = 'dono@acme.com', string $company = 'ACME'): User
{
    test()->post('/cadastro', [
        'company' => $company, 'name' => 'Dono', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

function driveDoc(Tenant|User $owner, ?Folder $folder, string $title, string $status = 'draft', array $extra = []): Envelope
{
    $tenantId = $owner instanceof User ? $owner->tenant_id : $owner->id;
    $doc = Document::create(['tenant_id' => $tenantId, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);

    return Envelope::create($extra + [
        'tenant_id' => $tenantId, 'document_id' => $doc->id, 'title' => $title, 'status' => $status,
        'verification_code' => strtoupper(Str::random(12)), 'folder_id' => $folder?->id,
    ]);
}

/** Pastas A > A1, B; documentos em A, A1 e sem pasta. */
function driveSetup(User $user): array
{
    $svc = app(FolderService::class);
    $a = $svc->create($user->tenant, 'A');
    $a1 = $svc->create($user->tenant, 'A1', $a);
    $b = $svc->create($user->tenant, 'B');
    driveDoc($user, $a, 'Doc na A');
    driveDoc($user, $a1, 'Doc na A1');
    driveDoc($user, null, 'Doc sem pasta');

    return [$a, $a1, $b];
}

it('sem pasta aberta lista todos os documentos como hoje e mostra os cartões das pastas primárias', function () {
    $user = driveRegister();
    [$a, $a1, $b] = driveSetup($user);

    $this->get('/envelopes')->assertOk()
        ->assertSee('Doc na A')->assertSee('Doc na A1')->assertSee('Doc sem pasta')
        ->assertSee('data-folder-card="'.$a->id.'"', false)->assertSee('data-folder-card="'.$b->id.'"', false)
        ->assertDontSee('data-folder-card="'.$a1->id.'"', false);
});

it('abrir uma pasta mostra migalhas, subpastas e filtra para a pasta e as subpastas', function () {
    $user = driveRegister();
    [$a, $a1] = driveSetup($user);

    $html = $this->get('/envelopes?folder='.$a->id)->assertOk()
        ->assertSee('Doc na A')->assertSee('Doc na A1')->assertDontSee('Doc sem pasta')
        ->assertSee('data-folder-card="'.$a1->id.'"', false)->assertDontSee('data-folder-card="'.$a->id.'"', false)
        ->getContent();

    preg_match('/<nav class="drive-breadcrumb[^"]*".*?<\/nav>/s', $html, $m);
    expect($m[0] ?? '')->toContain('Documentos')->toContain('A');
});

it('a migalha mostra o caminho inteiro numa subpasta', function () {
    $user = driveRegister();
    [, $a1] = driveSetup($user);

    $html = $this->get('/envelopes?folder='.$a1->id)->assertOk()->getContent();
    preg_match('/<nav class="drive-breadcrumb[^"]*".*?<\/nav>/s', $html, $m);

    expect(strip_tags($m[0] ?? ''))->toMatch('/Documentos\s*›\s*A\s*›\s*A1/u');
});

it('none continua mostrando só os documentos sem pasta', function () {
    $user = driveRegister();
    driveSetup($user);

    $this->get('/envelopes?folder=none')->assertOk()
        ->assertSee('Doc sem pasta')->assertDontSee('Doc na A1')->assertDontSee('Doc na A');
});

it('o filtro de pasta de outra empresa não vaza documentos nem nomes', function () {
    $user = driveRegister();
    driveSetup($user);
    $other = Tenant::create(['name' => 'Outra']);
    $alheia = app(FolderService::class)->create($other, 'Pasta Alheia');
    driveDoc($other, $alheia, 'Segredo da outra empresa');

    $this->get('/envelopes?folder='.$alheia->id)->assertOk()
        ->assertDontSee('Segredo da outra empresa')->assertDontSee('Pasta Alheia')->assertDontSee('Doc na A');
});

it('convive com status e arquivados', function () {
    $user = driveRegister();
    [$a] = driveSetup($user);
    driveDoc($user, $a, 'Concluído na A', 'completed');
    driveDoc($user, $a, 'Arquivado na A', 'completed', ['archived_at' => now()]);

    $this->get('/envelopes?folder='.$a->id.'&status=completed')->assertOk()
        ->assertSee('Concluído na A')->assertDontSee('Doc na A1');
    $this->get('/envelopes?folder='.$a->id.'&arquivados=1')->assertOk()
        ->assertSee('Arquivado na A')->assertDontSee('Concluído na A');
});

it('cada linha mostra o caminho da pasta e as etiquetas', function () {
    $user = driveRegister();
    [, $a1] = driveSetup($user);
    $tag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Urgente', 'color' => 'danger']);
    Envelope::where('title', 'Doc na A1')->firstOrFail()->tags()->attach($tag->id);

    $html = $this->get('/envelopes')->assertOk()->getContent();

    expect(strip_tags($html))->toMatch('/A\s*›\s*A1/u');
    $this->get('/envelopes')->assertSee('Urgente');
});

it('documentos na lixeira não contam nos cartões', function () {
    $user = driveRegister();
    [$a] = driveSetup($user);
    driveDoc($user, $a, 'No lixo', 'draft', ['trashed_at' => now()]);

    // A tem 2 documentos visíveis (na A e na A1); o da lixeira não conta
    $this->get('/envelopes')->assertOk()->assertSee('data-folder-card="'.$a->id.'" data-items="2"', false);
});

it('o filtro de pasta do formulário lista as pastas com o caminho', function () {
    $user = driveRegister();
    driveSetup($user);

    $this->get('/envelopes')->assertOk()->assertSee('A › A1');
});
