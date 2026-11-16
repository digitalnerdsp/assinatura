<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function wzPdfFile(string $name = 'contrato.pdf', string $content = WZ_PDF): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function wzUpload(User $u, array $extra = [], ?UploadedFile $file = null)
{
    return test()->actingAs($u)->post('/documentos/assistente', $extra + ['document' => $file ?? wzPdfFile()]);
}

it('o passo 1 mostra o título, a pasta e a zona de envio', function () {
    $u = wzOwner();
    $this->actingAs($u)->get('/documentos/assistente')->assertOk()
        ->assertSee('Novo documento')->assertSee('Alterar pasta')->assertSee('Qual documento será assinado?')->assertSee('Selecionar documento')->assertSee('Enviar documento');
});

it('exige entrar na conta', function () {
    $this->get('/documentos/assistente')->assertRedirect('/entrar');
    $this->post('/documentos/assistente', [])->assertRedirect('/entrar');
});

it('o upload cria o rascunho e leva ao passo 2', function () {
    $u = wzOwner();
    $res = wzUpload($u);

    $e = Envelope::query()->where('tenant_id', $u->tenant_id)->firstOrFail();
    $res->assertRedirect("/documentos/{$e->id}/passo/2");
    expect($e->status)->toBe(EnvelopeStatus::Draft)->and($e->title)->toBe('contrato')->and($e->wizard_step)->toBe(1)->and($e->created_by)->toBe($u->id);
});

it('usa o título e a pasta informados', function () {
    $u = wzOwner();
    $f = Folder::create(['tenant_id' => $u->tenant_id, 'name' => 'Contratos']);
    wzUpload($u, ['title' => 'Meu contrato', 'folder_id' => $f->id])->assertRedirect();

    $e = Envelope::firstOrFail();
    expect($e->title)->toBe('Meu contrato')->and($e->folder_id)->toBe($f->id);
});

it('recusa arquivo que não é PDF, PDF grande demais e pasta de outra empresa', function () {
    $u = wzOwner();
    wzUpload($u, [], wzPdfFile('a.pdf', 'isto não é pdf'))->assertSessionHasErrors('document');
    wzUpload($u, [], UploadedFile::fake()->create('a.txt', 1, 'text/plain'))->assertSessionHasErrors('document');
    $this->actingAs($u)->post('/documentos/assistente', [])->assertSessionHasErrors('document');

    $foreign = Folder::create(['tenant_id' => wzOwner('o@wz.test')->tenant_id, 'name' => 'Alheia']);
    wzUpload($u, ['folder_id' => $foreign->id])->assertSessionHasErrors('folder_id');

    config(['signing.max_pdf_mb' => 0]);
    wzUpload($u)->assertSessionHasErrors('document');
    expect(Envelope::count())->toBe(0);
});

it('só abre o passo em que o rascunho já chegou', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/3")->assertRedirect("/documentos/{$e->id}/passo/2");
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/4")->assertRedirect("/documentos/{$e->id}/passo/2");
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/2")->assertOk();
    $this->actingAs($u)->get("/documentos/{$e->id}/passo/5")->assertNotFound();
});

it('documento de outra empresa ou na lixeira dá 404 e enviado vai ao detalhe', function () {
    $u = wzOwner();
    $other = wzOwner('outro@wz.test');
    $mine = wzDraft($u);
    $theirs = wzDraft($other);

    $this->actingAs($u)->get("/documentos/{$theirs->id}/passo/1")->assertNotFound();
    $this->actingAs($u)->put("/documentos/{$theirs->id}/passo/1", ['title' => 'X'])->assertNotFound();

    $mine->update(['trashed_at' => now()]);
    $this->actingAs($u)->get("/documentos/{$mine->id}/passo/1")->assertNotFound();

    $mine->update(['trashed_at' => null, 'status' => EnvelopeStatus::Sent]);
    $this->actingAs($u)->get("/documentos/{$mine->id}/passo/1")->assertRedirect("/documentos/{$mine->id}");
    $this->actingAs($u)->put("/documentos/{$mine->id}/passo/1", ['title' => 'X'])->assertRedirect("/documentos/{$mine->id}");
    expect($mine->fresh()->title)->toBe('Contrato');
});

it('ao voltar ao passo 1 mostra o arquivo e permite trocar título, pasta e arquivo', function () {
    $u = wzOwner();
    $e = wzDraft($u, 'Contrato');
    $f = Folder::create(['tenant_id' => $u->tenant_id, 'name' => 'RH']);

    $this->actingAs($u)->get("/documentos/{$e->id}/passo/1")->assertOk()->assertSee('contrato.pdf')->assertSee('Subir outro arquivo');

    $this->actingAs($u)->put("/documentos/{$e->id}/passo/1", ['title' => 'Novo título', 'folder_id' => $f->id])->assertRedirect("/documentos/{$e->id}/passo/2");
    $e->refresh();
    expect($e->title)->toBe('Novo título')->and($e->folder_id)->toBe($f->id)->and($e->document->name)->toBe('contrato.pdf');

    $new = WZ_PDF.'%novo';
    $this->actingAs($u)->put("/documentos/{$e->id}/passo/1", ['title' => 'Novo título', 'document' => wzPdfFile('outro.pdf', $new)])->assertRedirect("/documentos/{$e->id}/passo/2");
    expect($e->fresh()->document->name)->toBe('outro.pdf')->and($e->fresh()->document->sha256)->toBe(hash('sha256', $new));
});

it('cria uma pasta pelo passo 1 e devolve o caminho', function () {
    $u = wzOwner();
    $parent = Folder::create(['tenant_id' => $u->tenant_id, 'name' => 'Contratos']);

    $r = $this->actingAs($u)->postJson('/documentos/pasta-rapida', ['name' => 'Aluguel', 'parent_id' => $parent->id])->assertOk()->assertJsonStructure(['id', 'path']);
    expect($r->json('path'))->toBe('Contratos › Aluguel')->and(Folder::find($r->json('id'))->tenant_id)->toBe($u->tenant_id);

    $this->actingAs($u)->postJson('/documentos/pasta-rapida', ['name' => 'Aluguel', 'parent_id' => $parent->id])->assertStatus(422)->assertJsonPath('message', 'Já existe uma pasta com este nome aqui.');
    $this->actingAs($u)->postJson('/documentos/pasta-rapida', ['name' => ''])->assertStatus(422);
    $foreign = Folder::create(['tenant_id' => wzOwner('o2@wz.test')->tenant_id, 'name' => 'Alheia']);
    $this->actingAs($u)->postJson('/documentos/pasta-rapida', ['name' => 'X', 'parent_id' => $foreign->id])->assertStatus(422);
});
