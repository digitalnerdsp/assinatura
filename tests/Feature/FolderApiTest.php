<?php

declare(strict_types=1);

use App\Folders\FolderService;
use App\Models\ApiKey;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{0:Tenant,1:array<string,string>} */
function folderApi(?Tenant $tenant = null, bool $readOnly = false): array
{
    $tenant ??= Tenant::create(['name' => 'ACME']);
    [, $plain] = ApiKey::issue($tenant, 'k', $readOnly);

    return [$tenant, ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json']];
}

function folderApiDoc(Tenant $tenant, ?Folder $folder, string $status, string $title = 'Doc'): Envelope
{
    $doc = Document::create(['tenant_id' => $tenant->id, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);

    return Envelope::create([
        'tenant_id' => $tenant->id, 'document_id' => $doc->id, 'title' => $title, 'status' => $status,
        'verification_code' => strtoupper(Str::random(12)), 'folder_id' => $folder?->id,
    ]);
}

it('cria subpasta com parent_id e devolve parent_id e caminho', function () {
    [, $h] = folderApi();

    $a = $this->postJson('/api/v1/folders', ['name' => 'A'], $h)->assertCreated()->assertJsonPath('parent_id', null)->assertJsonPath('path', 'A')->json();
    $this->postJson('/api/v1/folders', ['name' => 'B', 'parent_id' => $a['id']], $h)->assertCreated()
        ->assertJsonPath('parent_id', $a['id'])->assertJsonPath('path', 'A / B');
    $this->getJson('/api/v1/folders', $h)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.path', 'A / B');
});

it('recusa o 5º nível, nome repetido na mesma pasta e aceita o mesmo nome em outra', function () {
    [, $h] = folderApi();
    $parent = null;
    foreach (['N1', 'N2', 'N3', 'N4'] as $name) {
        $parent = $this->postJson('/api/v1/folders', ['name' => $name] + ($parent ? ['parent_id' => $parent['id']] : []), $h)->assertCreated()->json();
    }

    $this->postJson('/api/v1/folders', ['name' => 'N5', 'parent_id' => $parent['id']], $h)->assertStatus(422)->assertJsonPath('error', 'folder_too_deep');
    $this->postJson('/api/v1/folders', ['name' => 'n1'], $h)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    $this->postJson('/api/v1/folders', ['name' => 'N1', 'parent_id' => $parent['id']], $h)->assertStatus(422); // N4 já está no nível 4 (limite)
});

it('parent_id de outra empresa devolve 404 e nada é criado', function () {
    [, $h] = folderApi();
    $alheia = app(FolderService::class)->create(Tenant::create(['name' => 'Outra']), 'Alheia');

    $this->postJson('/api/v1/folders', ['name' => 'X', 'parent_id' => $alheia->id], $h)->assertNotFound();

    expect(Folder::where('name', 'X')->count())->toBe(0);
});

it('PATCH renomeia e move, e recusa ciclo e pasta de outra empresa', function () {
    [$tenant, $h] = folderApi();
    $svc = app(FolderService::class);
    $a = $svc->create($tenant, 'A');
    $b = $svc->create($tenant, 'B', $a);
    $alheia = $svc->create(Tenant::create(['name' => 'Outra']), 'Alheia');

    $this->patchJson("/api/v1/folders/{$a->id}", ['name' => 'Alfa'], $h)->assertOk()->assertJsonPath('name', 'Alfa');
    $this->patchJson("/api/v1/folders/{$a->id}", ['parent_id' => $b->id], $h)->assertStatus(422)->assertJsonPath('error', 'folder_cycle');
    $this->patchJson("/api/v1/folders/{$b->id}", ['parent_id' => null], $h)->assertOk()->assertJsonPath('parent_id', null);
    $this->patchJson("/api/v1/folders/{$b->id}", ['parent_id' => $alheia->id], $h)->assertNotFound();
    $this->patchJson("/api/v1/folders/{$alheia->id}", ['name' => 'Invadida'], $h)->assertNotFound();
    expect($alheia->fresh()->name)->toBe('Alheia');
});

it('DELETE aplica a regra e devolve os números', function () {
    [$tenant, $h] = folderApi();
    $a = app(FolderService::class)->create($tenant, 'A');
    folderApiDoc($tenant, $a, 'draft');
    folderApiDoc($tenant, $a, 'expired');
    $kept = folderApiDoc($tenant, $a, 'completed');

    $this->deleteJson("/api/v1/folders/{$a->id}", [], $h)->assertOk()->assertExactJson(['trashed' => 2, 'kept' => 1, 'folders' => 0]);

    expect(Folder::count())->toBe(0)->and($kept->fresh()->folder_id)->toBeNull()->and(Envelope::whereNotNull('trashed_at')->count())->toBe(2);
});

it('DELETE de pasta de outra empresa devolve 404', function () {
    [, $h] = folderApi();
    $alheia = app(FolderService::class)->create(Tenant::create(['name' => 'Outra']), 'Alheia');

    $this->deleteJson("/api/v1/folders/{$alheia->id}", [], $h)->assertNotFound();
    expect(Folder::find($alheia->id))->not->toBeNull();
});

it('PATCH de etiqueta renomeia e troca a cor, recusando duplicada e de outra empresa', function () {
    [$tenant, $h] = folderApi();
    Tag::create(['tenant_id' => $tenant->id, 'name' => 'Existente', 'color' => 'info']);
    $tag = Tag::create(['tenant_id' => $tenant->id, 'name' => 'Minha', 'color' => 'info']);
    $alheia = Tag::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Alheia', 'color' => 'info']);

    $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Renomeada', 'color' => 'danger'], $h)->assertOk()
        ->assertJsonPath('name', 'Renomeada')->assertJsonPath('color', 'danger');
    $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'Existente'], $h)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    $this->patchJson("/api/v1/tags/{$tag->id}", ['color' => 'rosa'], $h)->assertStatus(422);
    $this->patchJson("/api/v1/tags/{$alheia->id}", ['name' => 'Invadida'], $h)->assertNotFound();
});

it('chave somente leitura não altera pastas nem etiquetas', function () {
    [$tenant, $rw] = folderApi();
    [, $ro] = folderApi($tenant, readOnly: true);
    $a = app(FolderService::class)->create($tenant, 'A');
    $tag = Tag::create(['tenant_id' => $tenant->id, 'name' => 'T', 'color' => 'info']);

    $this->patchJson("/api/v1/folders/{$a->id}", ['name' => 'X'], $ro)->assertForbidden();
    $this->deleteJson("/api/v1/folders/{$a->id}", [], $ro)->assertForbidden();
    $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'X'], $ro)->assertForbidden();
});

it('o filtro folder_id da lista de envelopes inclui as subpastas', function () {
    [$tenant, $h] = folderApi();
    $svc = app(FolderService::class);
    $a = $svc->create($tenant, 'A');
    $a1 = $svc->create($tenant, 'A1', $a);
    folderApiDoc($tenant, $a, 'draft', 'Na A');
    folderApiDoc($tenant, $a1, 'draft', 'Na A1');
    folderApiDoc($tenant, null, 'draft', 'Sem pasta');

    $titles = collect($this->getJson('/api/v1/envelopes?folder_id='.$a->id, $h)->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

    expect($titles)->toBe(['Na A', 'Na A1']);
});

it('o OpenAPI documenta parent_id, o PATCH de pastas e etiquetas e a resposta do DELETE', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->assertOk()->json();

    expect($spec['paths']['/folders/{id}'])->toHaveKeys(['patch', 'delete'])
        ->and($spec['paths']['/tags/{id}'])->toHaveKeys(['patch', 'delete'])
        ->and(json_encode($spec['paths']['/folders']['post']))->toContain('parent_id')
        ->and(json_encode($spec['paths']['/folders/{id}']['delete']))->toContain('trashed')
        ->and(json_encode($spec['components']['schemas']['Folder']))->toContain('parent_id')->toContain('path');
});
