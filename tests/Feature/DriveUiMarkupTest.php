<?php

declare(strict_types=1);

use App\Folders\FolderService;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function uiPage(): array
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');
    $user = User::where('email', 'dono@acme.com')->firstOrFail();

    $svc = app(FolderService::class);
    $a = $svc->create($user->tenant, 'Pasta A');
    $svc->create($user->tenant, 'Subpasta A1', $a);
    $other = Tenant::create(['name' => 'Outra']);
    $svc->create($other, 'Pasta Alheia');
    $tag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Urgente', 'color' => 'danger']);
    $doc = Document::create(['tenant_id' => $user->tenant_id, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);
    $envelope = Envelope::create([
        'tenant_id' => $user->tenant_id, 'document_id' => $doc->id, 'title' => 'Contrato X', 'status' => 'draft',
        'verification_code' => strtoupper(Str::random(12)), 'folder_id' => $a->id,
    ]);
    $envelope->tags()->attach($tag->id);

    return [$user, $a, $tag, $envelope, test()->get('/envelopes')->assertOk()->getContent()];
}

it('traz os botões Nova pasta e Nova etiqueta e os modais em tela cheia no celular', function () {
    [, , , , $html] = uiPage();

    expect($html)->toContain('data-drive-action="new-folder"')->toContain('Nova pasta')
        ->toContain('data-drive-action="new-tag"')->toContain('Nova etiqueta');
    foreach (['modal-folder', 'modal-tag', 'modal-move', 'modal-delete-folder', 'modal-doc-tags'] as $id) {
        expect($html)->toMatch('/id="'.$id.'"[^>]*class="[^"]*modal[^"]*"|class="[^"]*modal[^"]*"[^>]*id="'.$id.'"/s');
    }
    expect(substr_count($html, 'modal-fullscreen-sm-down'))->toBeGreaterThanOrEqual(5);
});

it('cada cartão de pasta, chip de etiqueta e documento tem menu ⋮ por toque', function () {
    [, , , , $html] = uiPage();

    expect($html)->toContain('aria-label="Ações da pasta Pasta A"')
        ->toContain('aria-label="Ações da etiqueta Urgente"')
        ->toContain('aria-label="Ações do documento Contrato X"');
    expect(substr_count($html, 'data-bs-toggle="dropdown"'))->toBeGreaterThanOrEqual(3);
    foreach (['rename-folder', 'move-folder', 'delete-folder', 'edit-tag', 'delete-tag', 'move-doc', 'tags-doc'] as $action) {
        expect($html)->toContain('data-drive-action="'.$action.'"');
    }
});

it('os menus não são cortados pela tabela responsiva', function () {
    [, , , , $html] = uiPage();

    preg_match_all('/<button[^>]*data-bs-toggle="dropdown"[^>]*>/', $html, $m);
    expect($m[0])->not->toBeEmpty();
    foreach ($m[0] as $button) {
        expect($button)->toContain('data-bs-popper-config');
        expect(html_entity_decode($button))->toContain('"strategy":"fixed"');
    }
});

it('o modal Mover para… embute a árvore só da empresa', function () {
    [, , , , $html] = uiPage();

    preg_match('/id="modal-move"[^>]*data-tree="([^"]*)"/', $html, $m);
    $tree = json_decode(html_entity_decode($m[1] ?? '[]'), true);

    expect($tree)->toBeArray()->not->toBeEmpty()
        ->and($tree[0]['name'])->toBe('Pasta A')->and($tree[0]['children'][0]['name'])->toBe('Subpasta A1');
    expect($m[1] ?? '')->not->toContain('Alheia');
});

it('os alvos de toque e os arquivos de estilo e script estão presentes', function () {
    [, , , , $html] = uiPage();

    expect($html)->toContain('drive-tap')->toContain('assets/css/drive.css?v=')->toContain('assets/js/drive.js?v=');
    expect(file_get_contents(public_path('assets/css/drive.css')))->toContain('min-height: 44px')->toContain('min-width: 44px');
});

it('os endereços das ações vêm das rotas (modelos com :id) e a tabela vira cartões no celular', function () {
    [, , , , $html] = uiPage();

    expect($html)->toContain('/organizacao/pastas')->toContain('/organizacao/etiquetas')->toContain('data-url-store=');
    expect($html)->toContain('drive-docs')->toContain('data-label="Status"');
});
