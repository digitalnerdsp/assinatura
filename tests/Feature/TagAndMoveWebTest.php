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

function tagRegister(string $email = 'dono@acme.com', string $company = 'ACME'): User
{
    test()->post('/cadastro', [
        'company' => $company, 'name' => 'Dono', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

function tagDoc(string $tenantId, string $title = 'Doc', array $extra = []): Envelope
{
    $doc = Document::create(['tenant_id' => $tenantId, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);

    return Envelope::create($extra + [
        'tenant_id' => $tenantId, 'document_id' => $doc->id, 'title' => $title, 'status' => 'draft',
        'verification_code' => strtoupper(Str::random(12)),
    ]);
}

it('renomeia e troca a cor da etiqueta', function () {
    $user = tagRegister();
    $tag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Urgente', 'color' => 'danger']);

    $this->put("/organizacao/etiquetas/{$tag->id}", ['name' => 'Muito urgente', 'color' => 'warning'])
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Etiqueta atualizada.');

    expect($tag->fresh()->name)->toBe('Muito urgente')->and($tag->fresh()->color)->toBe('warning');
    $this->put("/organizacao/etiquetas/{$tag->id}", ['name' => 'MUITO URGENTE', 'color' => 'warning'])->assertSessionHasNoErrors(); // só a caixa muda
});

it('recusa nome duplicado e cor fora da lista na etiqueta', function () {
    $user = tagRegister();
    Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Existente', 'color' => 'info']);
    $tag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Minha', 'color' => 'info']);

    $this->put("/organizacao/etiquetas/{$tag->id}", ['name' => 'Existente', 'color' => 'info'])->assertSessionHasErrors('name');
    $this->put("/organizacao/etiquetas/{$tag->id}", ['name' => 'Minha', 'color' => 'rosa-choque'])->assertSessionHasErrors('color');
    expect($tag->fresh()->name)->toBe('Minha');
});

it('etiqueta de outra empresa devolve 404 ao editar', function () {
    $alheia = Tag::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Alheia', 'color' => 'info']);
    tagRegister();

    $this->put("/organizacao/etiquetas/{$alheia->id}", ['name' => 'Invadida', 'color' => 'info'])->assertNotFound();
    expect($alheia->fresh()->name)->toBe('Alheia');
});

it('mover documento para pasta, para a raiz e entre pastas', function () {
    $user = tagRegister();
    $a = app(FolderService::class)->create($user->tenant, 'A');
    $b = app(FolderService::class)->create($user->tenant, 'B');
    $doc = tagDoc($user->tenant_id);

    $this->post("/envelopes/{$doc->id}/mover", ['folder_id' => $a->id])->assertSessionHasNoErrors()->assertSessionHas('status', 'Documento movido.');
    expect($doc->fresh()->folder_id)->toBe($a->id);

    $this->post("/envelopes/{$doc->id}/mover", ['folder_id' => $b->id]);
    expect($doc->fresh()->folder_id)->toBe($b->id);

    $this->post("/envelopes/{$doc->id}/mover", ['folder_id' => ''])->assertSessionHasNoErrors();
    expect($doc->fresh()->folder_id)->toBeNull();
});

it('pasta de outra empresa ao mover documento é recusada e documento de outra empresa dá 404', function () {
    $user = tagRegister();
    $doc = tagDoc($user->tenant_id);
    $alheia = Folder::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Alheia']);
    $docAlheio = tagDoc($alheia->tenant_id, 'Alheio');

    $this->post("/envelopes/{$doc->id}/mover", ['folder_id' => $alheia->id])->assertSessionHasErrors('folder_id');
    expect($doc->fresh()->folder_id)->toBeNull();

    $mine = app(FolderService::class)->create($user->tenant, 'Minha');
    $this->post("/envelopes/{$docAlheio->id}/mover", ['folder_id' => $mine->id])->assertNotFound();
    expect($docAlheio->fresh()->folder_id)->toBeNull();
});

it('mover vale para documento arquivado e é recusado na lixeira', function () {
    $user = tagRegister();
    $a = app(FolderService::class)->create($user->tenant, 'A');
    $archived = tagDoc($user->tenant_id, 'Arquivado', ['status' => 'completed', 'archived_at' => now()]);
    $trashed = tagDoc($user->tenant_id, 'Lixo', ['trashed_at' => now()]);

    $this->post("/envelopes/{$archived->id}/mover", ['folder_id' => $a->id])->assertSessionHasNoErrors();
    expect($archived->fresh()->folder_id)->toBe($a->id);

    $this->post("/envelopes/{$trashed->id}/mover", ['folder_id' => $a->id])->assertSessionHasErrors('envelope');
    expect($trashed->fresh()->folder_id)->toBeNull();
});

it('aplica e remove etiquetas do documento', function () {
    $user = tagRegister();
    $t1 = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'T1', 'color' => 'info']);
    $t2 = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'T2', 'color' => 'info']);
    $doc = tagDoc($user->tenant_id);

    $this->post("/envelopes/{$doc->id}/etiquetas", ['tags' => [$t1->id, $t2->id]])->assertSessionHasNoErrors();
    expect($doc->tags()->pluck('tags.id')->sort()->values()->all())->toBe(collect([$t1->id, $t2->id])->sort()->values()->all());

    $this->post("/envelopes/{$doc->id}/etiquetas", ['tags' => [$t2->id]]);
    expect($doc->tags()->pluck('tags.id')->all())->toBe([$t2->id]);

    $this->post("/envelopes/{$doc->id}/etiquetas")->assertSessionHasNoErrors(); // sem nenhuma: remove todas
    expect($doc->tags()->count())->toBe(0);
});

it('recusa etiqueta de outra empresa ao etiquetar e não altera nada', function () {
    $user = tagRegister();
    $mine = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Minha', 'color' => 'info']);
    $alheia = Tag::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Alheia', 'color' => 'info']);
    $doc = tagDoc($user->tenant_id);
    $doc->tags()->attach($mine->id);

    $this->post("/envelopes/{$doc->id}/etiquetas", ['tags' => [$alheia->id]])->assertSessionHasErrors('tags');

    expect($doc->tags()->pluck('tags.id')->all())->toBe([$mine->id]);
});

it('apagar etiqueta a tira dos documentos e não apaga os documentos', function () {
    $user = tagRegister();
    $tag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Some', 'color' => 'info']);
    $doc = tagDoc($user->tenant_id);
    $doc->tags()->attach($tag->id);

    $this->delete("/organizacao/etiquetas/{$tag->id}")->assertSessionHasNoErrors();

    expect(Tag::find($tag->id))->toBeNull()->and(Envelope::find($doc->id))->not->toBeNull()->and($doc->tags()->count())->toBe(0);
});
