<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\TrashService;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\SignatureField;
use App\Plans\StorageUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function edPdf(string $tag = 'x'): string
{
    return "%PDF-1.4\n% {$tag}\n%%EOF\n";
}

it('um envio comum tem um arquivo só, o principal', function () {
    $e = wzDraft(wzOwner());
    $files = $e->files();

    expect($files)->toHaveCount(1)->and($files[0]->n)->toBe(1)->and($files[0]->extraId)->toBeNull()
        ->and($files[0]->name)->toBe('contrato.pdf')->and($files[0]->finalPath)->toBeNull();
});

it('addDocument acrescenta arquivos depois do principal, numerados em ordem', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $svc = app(EnvelopeService::class);

    $svc->addDocument($e, edPdf('a'), 'anexo-a.pdf');
    $svc->addDocument($e, edPdf('b'), 'anexo-b.pdf');
    $files = $e->fresh()->files();

    expect($files->pluck('name')->all())->toBe(['contrato.pdf', 'anexo-a.pdf', 'anexo-b.pdf'])
        ->and($files->pluck('n')->all())->toBe([1, 2, 3])
        ->and(Storage::disk('local')->exists($files[1]->storagePath))->toBeTrue()
        ->and($e->evidenceEvents()->where('type', 'document_added')->count())->toBe(2);
});

it('addDocument só vale em rascunho, só com PDF e respeita o limite de arquivos', function () {
    $e = wzDraft(wzOwner());
    $svc = app(EnvelopeService::class);

    expect(fn () => $svc->addDocument($e, 'não é pdf', 'x.pdf'))->toThrow(FlowException::class, 'PDF');

    config(['signing.max_documents' => 2]);
    $svc->addDocument($e, edPdf(), 'a.pdf');
    expect(fn () => $svc->addDocument($e->fresh(), edPdf('2'), 'b.pdf'))->toThrow(FlowException::class, 'no máximo 2');

    $e->update(['status' => 'sent']);
    config(['signing.max_documents' => 10]);
    expect(fn () => $svc->addDocument($e->fresh(), edPdf('3'), 'c.pdf'))->toThrow(FlowException::class, 'rascunhos');
});

it('o armazenamento da empresa soma os arquivos acrescentados e devolve ao remover', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $svc = app(EnvelopeService::class);
    $before = (int) $u->tenant->fresh()->storage_bytes;

    $extra = $svc->addDocument($e, edPdf('grande'), 'a.pdf');
    expect((int) $u->tenant->fresh()->storage_bytes)->toBe($before + strlen(edPdf('grande')));

    $svc->removeDocument($e->fresh(), $extra->id);
    expect((int) $u->tenant->fresh()->storage_bytes)->toBe($before);
});

it('removeDocument apaga o arquivo, a linha e os campos desse arquivo, e não mexe no principal', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $svc = app(EnvelopeService::class);
    $extra = $svc->addDocument($e, edPdf(), 'a.pdf');
    $path = $extra->document->storage_path;
    $signer = $e->signers()->create(['name' => 'Ana', 'email' => 'a@x.com', 'role' => 'signer', 'position' => 1, 'status' => 'pending', 'token_hash' => 'h']);
    SignatureField::query()->create(['envelope_id' => $e->id, 'signer_id' => $signer->id, 'page' => 1, 'x' => .1, 'y' => .1, 'envelope_document_id' => $extra->id]);
    SignatureField::query()->create(['envelope_id' => $e->id, 'signer_id' => $signer->id, 'page' => 1, 'x' => .2, 'y' => .2]);

    $svc->removeDocument($e->fresh(), $extra->id);

    expect(Storage::disk('local')->exists($path))->toBeFalse()->and($e->fresh()->files())->toHaveCount(1)
        ->and($e->signatureFields()->count())->toBe(1)->and(Document::query()->whereKey($extra->document_id)->exists())->toBeFalse();
    expect(fn () => $svc->removeDocument($e->fresh(), 'nao-existe'))->toThrow(FlowException::class);
});

it('reconcile conta também os PDFs selados dos arquivos acrescentados', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $extra = app(EnvelopeService::class)->addDocument($e, edPdf(), 'a.pdf');
    $extra->update(['final_path' => 'x/final.pdf', 'final_size' => 5000]);
    $e->update(['final_path' => 'x/main.pdf', 'final_size' => 7000]);

    $total = app(StorageUsage::class)->reconcile($u->tenant);

    expect($total)->toBe((int) Document::query()->where('tenant_id', $u->tenant_id)->sum('size') + 12000);
});

it('a limpeza da lixeira apaga também os arquivos acrescentados', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $extra = app(EnvelopeService::class)->addDocument($e, edPdf(), 'a.pdf');
    $path = $extra->document->storage_path;
    $e->forceFill(['trashed_at' => now()->subDays(40)])->save();

    expect(app(TrashService::class)->purge(30))->toBe(1);

    expect(Document::query()->count())->toBe(0)->and(Storage::disk('local')->exists($path))->toBeFalse();
});

// ------------------------------------------------------------------ assistente (web)

use Illuminate\Http\UploadedFile;

function edUp(string $name, string $tag = 'x'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, edPdf($tag));
}

it('o passo 1 cria o envio com vários arquivos: o primeiro é o principal', function () {
    $u = wzOwner();

    $this->actingAs($u)->post('/documentos', ['documents' => [edUp('contrato.pdf', '1'), edUp('anexo.pdf', '2'), edUp('termo.pdf', '3')]])->assertSessionHasNoErrors()->assertRedirect();

    $e = Envelope::query()->latest()->first();
    expect($e->files()->pluck('name')->all())->toBe(['contrato.pdf', 'anexo.pdf', 'termo.pdf'])->and($e->title)->toBe('contrato');
});

it('continua aceitando o campo antigo de um arquivo só', function () {
    $u = wzOwner();
    $this->actingAs($u)->post('/documentos', ['document' => edUp('a.pdf')])->assertSessionHasNoErrors();
    expect(Envelope::query()->latest()->first()->files())->toHaveCount(1);
});

it('passa do limite de arquivos: recusa tudo e não cria nada', function () {
    config(['signing.max_documents' => 2]);
    $u = wzOwner();

    $this->actingAs($u)->post('/documentos', ['documents' => [edUp('a.pdf', '1'), edUp('b.pdf', '2'), edUp('c.pdf', '3')]])->assertSessionHasErrors('documents');
    expect(Envelope::query()->count())->toBe(0);
});

it('um arquivo ruim no meio do lote impede o envio inteiro', function () {
    $u = wzOwner();
    $bad = UploadedFile::fake()->createWithContent('b.pdf', 'isto não é pdf');

    $this->actingAs($u)->post('/documentos', ['documents' => [edUp('a.pdf'), $bad]])->assertSessionHasErrors('document');
    expect(Envelope::query()->count())->toBe(0);
});

it('no passo 1 de um rascunho dá para acrescentar e remover arquivos', function () {
    $u = wzOwner();
    $e = wzDraft($u);

    $this->actingAs($u)->put("/documentos/{$e->id}/passo/1", ['documents' => [edUp('a.pdf', 'a'), edUp('b.pdf', 'b')], 'title' => 'T'])->assertSessionHasNoErrors();
    $e = $e->fresh();
    expect($e->files()->pluck('name')->all())->toBe(['contrato.pdf', 'a.pdf', 'b.pdf']);

    $this->actingAs($u)->put("/documentos/{$e->id}/passo/1", ['remove' => [$e->files()[1]->extraId], 'title' => 'T'])->assertSessionHasNoErrors();
    expect($e->fresh()->files()->pluck('name')->all())->toBe(['contrato.pdf', 'b.pdf']);
});

it('o passo 1 mostra a lista de arquivos do rascunho', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    app(EnvelopeService::class)->addDocument($e, edPdf(), 'anexo-um.pdf');

    $this->actingAs($u)->get("/documentos/{$e->id}/passo/1")->assertOk()->assertSee('contrato.pdf')->assertSee('anexo-um.pdf');
});

it('os campos de assinatura guardam em qual arquivo estão', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    $extra = app(EnvelopeService::class)->addDocument($e, edPdf(), 'a.pdf');
    wzSync($e->fresh(), $u, [wzPerson('Ana', 'ana@x.com')]);
    $ana = $e->signers()->where('role', 'signer')->first();
    $f = fn ($doc) => ['signer_id' => (string) $ana->id, 'type' => 'signature', 'page' => 1, 'x' => .1, 'y' => .1, 'width' => .2, 'height' => .05] + ($doc ? ['document_id' => $doc] : []);

    app(EnvelopeService::class)->syncFields($e->fresh(), [$f(null), $f($extra->id)]);

    expect($e->signatureFields()->whereNull('envelope_document_id')->count())->toBe(1)
        ->and($e->signatureFields()->where('envelope_document_id', $extra->id)->count())->toBe(1);
    expect(fn () => app(EnvelopeService::class)->syncFields($e->fresh(), [$f('00000000-0000-4000-8000-000000000000')]))->toThrow(FlowException::class, 'arquivo');
});

it('o passo 3 mostra uma aba por arquivo e entrega os campos de cada um', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    app(EnvelopeService::class)->addDocument($e, edPdf(), 'anexo-um.pdf');
    wzSync($e->fresh(), $u, [wzPerson('Ana', 'ana@x.com')]);

    $this->actingAs($u)->get("/documentos/{$e->id}/passo/3")->assertOk()->assertSee('anexo-um.pdf')->assertSee('data-docs', false);
});

it('o remetente baixa o original de cada arquivo pelo número', function () {
    $u = wzOwner();
    $e = wzDraft($u);
    app(EnvelopeService::class)->addDocument($e, edPdf('segundo'), 'segundo.pdf');

    $r = $this->actingAs($u)->get("/documentos/{$e->id}/original?n=2");
    $r->assertOk();
    expect($r->streamedContent())->toContain('segundo');
    $this->actingAs($u)->get("/documentos/{$e->id}/original?n=9")->assertNotFound();
    expect($this->actingAs($u)->get("/documentos/{$e->id}/original")->streamedContent())->not->toContain('segundo');
});

// ------------------------------------------------------------------ tela do signatário

use App\Envelopes\SigningService;
use App\Jobs\SealEnvelope;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function edSent(): array
{
    Mail::fake();
    Queue::fake([SealEnvelope::class]);
    $u = wzOwner();
    \App\Models\TenantSigningSetting::query()->updateOrCreate(['tenant_id' => $u->tenant_id], ['sender_policy' => 'never']);
    $e = wzDraft($u);
    app(EnvelopeService::class)->addDocument($e, edPdf('segundo'), 'segundo.pdf');
    wzSync($e->fresh(), $u, [wzPerson('Ana', 'ana@x.com')]);
    app(EnvelopeService::class)->syncFields($e->fresh(), []);
    app(EnvelopeService::class)->send($e->fresh());

    return [$u, $e->fresh(), $e->signers()->where('role', 'signer')->first()->signUrl()];
}

it('o signatário vê a lista dos arquivos e abre cada um', function () {
    [, $e, $url] = edSent();

    $this->get($url)->assertOk()->assertSee('contrato.pdf')->assertSee('segundo.pdf');
    expect($this->get($url.'/pdf?n=2')->getContent())->toContain('segundo');
    expect($this->get($url.'/pdf')->getContent())->not->toContain('segundo');
    $this->get($url.'/pdf?n=7')->assertNotFound();
});

it('uma assinatura vale para todos os arquivos', function () {
    [, $e, $url] = edSent();
    $ana = $e->signers()->where('role', 'signer')->first();

    app(SigningService::class)->sign($ana, 'typed', 'Ana', true);

    expect($e->fresh()->status)->toBe(\App\Enums\EnvelopeStatus::Sealing);
});

// ------------------------------------------------------------------ selagem em lote

use App\Enums\EnvelopeStatus;
use App\Signing\Exceptions\SigningException;

/** Envio com 2 arquivos, a Ana já assinou (status "sealing"). Campos: um no principal, dois no segundo. */
function edReadyToSeal(): array
{
    [$u, $e, $url] = edSent();
    $ana = $e->signers()->where('role', 'signer')->first();
    $extra = $e->extraDocuments()->first();
    foreach ([[null, .1], [$extra->id, .2], [$extra->id, .3]] as [$doc, $y]) {
        SignatureField::query()->create(['envelope_id' => $e->id, 'signer_id' => $ana->id, 'page' => 1, 'x' => .1, 'y' => $y, 'width' => .2, 'height' => .05, 'type' => 'signature', 'envelope_document_id' => $doc]);
    }
    app(SigningService::class)->sign($ana, 'typed', 'Ana', true);

    return [$u, $e->fresh()];
}

it('sela cada arquivo separadamente, cada um com o seu relatório e só os seus campos', function () {
    [, $e] = edReadyToSeal();
    $seen = [];

    wzSealNow($e, function ($request) use (&$seen) {
        $seen[] = [$request->report['document_name'], count($request->report['placements']), str_contains($request->pdf, 'segundo')];
    });

    expect($seen)->toBe([['contrato.pdf', 1, false], ['segundo.pdf', 2, true]]);
    $e = $e->fresh();
    $files = $e->files();
    expect($e->status)->toBe(EnvelopeStatus::Completed)->and($files->every(fn ($f) => $f->finalPath !== null && Storage::disk('local')->exists($f->finalPath)))->toBeTrue()
        ->and($files[0]->finalPath)->not->toBe($files[1]->finalPath)
        ->and($e->evidenceEvents()->where('type', 'document_sealed')->count())->toBe(2);
});

it('se um arquivo falha, o envio continua em selagem e a nova tentativa sela só o que falta', function () {
    [, $e] = edReadyToSeal();

    expect(fn () => wzSealNow($e, function ($r, $call) {
        if ($call === 2) {
            throw new SigningException('selador fora do ar');
        }
    }))->toThrow(SigningException::class);

    $e = $e->fresh();
    expect($e->status)->toBe(EnvelopeStatus::Sealing)->and($e->files()[0]->finalPath)->not->toBeNull()->and($e->files()[1]->finalPath)->toBeNull();

    $calls = 0;
    wzSealNow($e, function () use (&$calls) { $calls++; });

    expect($calls)->toBe(1)->and($e->fresh()->status)->toBe(EnvelopeStatus::Completed)->and($e->fresh()->files()[1]->finalPath)->not->toBeNull();
});

it('o armazenamento soma o PDF selado de cada arquivo e o envio conta uma vez no plano', function () {
    [$u, $e] = edReadyToSeal();
    $before = (int) $u->tenant->fresh()->storage_bytes;

    wzSealNow($e);

    $sizes = $e->fresh()->files()->sum('finalSize');
    expect((int) $u->tenant->fresh()->storage_bytes)->toBe($before + $sizes);
});

it('o relatório de cada arquivo traz o nome e o hash daquele arquivo', function () {
    [, $e] = edReadyToSeal();
    $names = [];

    wzSealNow($e, function ($request) use (&$names) { $names[$request->report['document_name']] = $request->report['document_sha256']; });

    $files = $e->fresh()->files();
    expect($names)->toBe([$files[0]->name => $files[0]->sha256, $files[1]->name => $files[1]->sha256]);
});

// ------------------------------------------------------------------ entrega: e-mail, download, verificação, API

function edCompleted(): array
{
    [$u, $e] = edReadyToSeal();
    wzSealNow($e);
    $e = $e->fresh();

    return [$u, $e, $e->signers()->where('role', 'signer')->first()->signUrl()];
}

it('os e-mails de cópia levam todos os PDFs selados em anexo', function () {
    [$u, $e] = edCompleted();

    Mail::assertSent(\App\Mail\SignedCopyMail::class, fn ($m) => $m->hasTo('ana@x.com') && count($m->attachments()) === 2);
    Mail::assertSent(\App\Mail\SignedCopyMail::class, fn ($m) => $m->hasTo($u->email) && count($m->attachments()) === 2);
});

it('a tela final lista cada arquivo com baixar e ver, e um botão para baixar todos', function () {
    [, , $url] = edCompleted();

    $this->followingRedirects()->get($url)->assertOk()->assertSee('contrato.pdf')->assertSee('segundo.pdf')->assertSee('Baixar todos')
        ->assertSee($url.'/assinado/baixar?n=2', false)->assertSee($url.'/assinado/baixar-todos', false);
});

it('cada arquivo baixa o seu PDF selado e o número inválido dá 404', function () {
    [, $e, $url] = edCompleted();

    expect($this->get($url.'/assinado/baixar?n=2')->streamedContent ?? $this->get($url.'/assinado/baixar?n=2')->getContent())->toContain('segundo');
    expect($this->get($url.'/assinado/baixar?n=1')->getContent())->not->toContain('segundo');
    $this->get($url.'/assinado/baixar?n=9')->assertNotFound();
    expect($e->evidenceEvents()->where('type', 'signed_copy_downloaded')->count())->toBeGreaterThanOrEqual(2);
});

it('baixar todos entrega um zip com um PDF por arquivo', function () {
    [, , $url] = edCompleted();

    $r = $this->get($url.'/assinado/baixar-todos');
    $r->assertOk();
    expect($r->headers->get('content-type'))->toContain('zip');
    $tmp = tempnam(sys_get_temp_dir(), 'z');
    file_put_contents($tmp, $r->getContent());
    $zip = new ZipArchive();
    $zip->open($tmp);
    expect($zip->numFiles)->toBe(2)->and([$zip->getNameIndex(0), $zip->getNameIndex(1)])->toBe(['assinado-contrato.pdf', 'assinado-segundo.pdf']);
    $zip->close();
    unlink($tmp);
});

it('antes de selar não há zip', function () {
    [, $e, $url] = edSent();
    app(SigningService::class)->sign($e->signers()->where('role', 'signer')->first(), 'typed', 'Ana', true);

    $this->get($url.'/assinado/baixar-todos')->assertNotFound();
});

it('a verificação pública lista os arquivos e reconhece o hash de qualquer um deles', function () {
    [, $e] = edCompleted();
    $files = $e->files();

    $this->get("/verificar/{$e->verification_code}")->assertOk()->assertSee('contrato.pdf')->assertSee('segundo.pdf')->assertSee($files[1]->finalSha256)->assertSee($files[1]->sha256);

    $upload = fn (string $bytes) => UploadedFile::fake()->createWithContent('x.pdf', $bytes);
    $this->post("/verificar/{$e->verification_code}", ['file' => $upload(Storage::disk('local')->get($files[1]->finalPath))])->assertSee('idêntico ao documento selado');
    $this->post("/verificar/{$e->verification_code}", ['file' => $upload(Storage::disk('local')->get($files[1]->storagePath))])->assertSee('idêntico ao documento original');
    $this->post("/verificar/{$e->verification_code}", ['file' => $upload('outro')])->assertSee('NÃO corresponde');
});

it('a API devolve a lista de arquivos e baixa cada um pelo número', function () {
    [$u, $e] = edCompleted();
    $presented = app(\App\Api\Presenter::class)->envelope($e->fresh());

    expect($presented['documents'])->toHaveCount(2)->and($presented['documents'][1])->toMatchArray(['n' => 2, 'name' => 'segundo.pdf'])
        ->and($presented['file']['name'])->toBe('contrato.pdf')->and($presented['final_sha256'])->toBe($e->fresh()->files()[0]->finalSha256);
});

it('o webhook de conclusão lista os arquivos', function () {
    [, $e] = edCompleted();

    $payload = \App\Webhooks\EnvelopePayload::make($e->fresh());

    expect($payload['document']['files'])->toHaveCount(2)->and($payload['document']['files'][1]['name'])->toBe('segundo.pdf');
});

it('a tela do remetente tem um botão de original e de selado para cada arquivo', function () {
    [$u, $e] = edCompleted();

    $this->actingAs($u)->get("/documentos/{$e->id}")->assertOk()->assertSee('n=2', false)->assertSee('segundo.pdf');
});
