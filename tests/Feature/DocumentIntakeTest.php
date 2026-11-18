<?php

declare(strict_types=1);

use App\Documents\DocumentIntake;
use App\Envelopes\Exceptions\FlowException;
use App\Signing\Support\SealerClient;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\UploadedFile;

function intakeSealer($response): void
{
    $http = new Http();
    $http->fake(['sealer/v1/convert' => $response]);
    app()->instance(SealerClient::class, new SealerClient($http, 'http://sealer', 't'));
}

function upload(string $name, string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('PDF passa direto, sem converter', function () {
    $r = (new DocumentIntake())->fromUpload(upload('contrato.pdf', '%PDF-1.4 x'));
    expect($r['pdf'])->toBe('%PDF-1.4 x')->and($r['name'])->toBe('contrato.pdf')->and($r['source_sha256'])->toBeNull();
});

it('DOCX é convertido e o nome vira .pdf, guardando o hash do original', function () {
    intakeSealer(Http::response('%PDF-1.7 conv', 200));
    $r = (new DocumentIntake())->fromUpload(upload('Contrato Final.docx', 'PKdocx'));
    expect($r['pdf'])->toBe('%PDF-1.7 conv')->and($r['name'])->toBe('Contrato Final.pdf')
        ->and($r['source_sha256'])->toBe(hash('sha256', 'PKdocx'))->and($r['source_name'])->toBe('Contrato Final.docx');
});

it('arquivo de outro tipo é recusado, mesmo com extensão pdf ou docx', function () {
    foreach ([['a.exe', 'MZ...'], ['a.pdf', 'texto qualquer'], ['a.docx', 'texto qualquer'], ['a.zip', 'PK..']] as [$n, $b]) {
        expect(fn () => (new DocumentIntake())->fromUpload(upload($n, $b)))->toThrow(FlowException::class, 'PDF ou DOCX');
    }
});

it('erros do selador viram mensagens claras', function () {
    intakeSealer(Http::response(['error' => 'invalid_docx'], 422));
    expect(fn () => (new DocumentIntake())->fromUpload(upload('a.docx', 'PK')))->toThrow(FlowException::class, 'Word');

    intakeSealer(Http::response(['error' => 'timeout'], 422));
    expect(fn () => (new DocumentIntake())->fromUpload(upload('a.docx', 'PK')))->toThrow(FlowException::class, 'demorou');

    intakeSealer(Http::response(['error' => 'unavailable'], 503));
    expect(fn () => (new DocumentIntake())->fromUpload(upload('a.docx', 'PK')))->toThrow(FlowException::class, 'indisponível');
});

// ------------------------------------------------------------------ passo 1 do assistente

use App\Models\Envelope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function intakeUser(): User
{
    return wzOwner();
}

it('o passo 1 aceita DOCX: guarda o PDF convertido e registra o original na trilha', function () {
    Storage::fake('local');
    intakeSealer(Http::response("%PDF-1.7\n%%EOF\n", 200));
    $u = intakeUser();

    $this->actingAs($u)->post('/documentos', ['document' => upload('Proposta.docx', 'PKdocx'), 'title' => ''])->assertSessionHasNoErrors()->assertRedirect();

    $e = Envelope::query()->latest()->first();
    expect($e->document->name)->toBe('Proposta.pdf')->and($e->title)->toBe('Proposta')
        ->and($e->evidenceEvents()->where('type', 'source_converted')->count())->toBe(1);
});

it('o passo 1 recusa arquivo que não é PDF nem DOCX, e DOCX que o selador não converte', function () {
    Storage::fake('local');
    $u = intakeUser();

    $this->actingAs($u)->post('/documentos', ['document' => upload('a.txt', 'oi')])->assertSessionHasErrors('document');
    intakeSealer(Http::response(['error' => 'convert_failed'], 422));
    $this->actingAs($u)->post('/documentos', ['document' => upload('a.docx', 'PKx')])->assertSessionHasErrors('document');
    expect(Envelope::query()->count())->toBe(0);
});
