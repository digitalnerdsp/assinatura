<?php

declare(strict_types=1);

use App\Envelopes\Exceptions\FlowException;
use App\Models\DocumentTemplate;
use App\Models\Plan;
use App\Models\TemplateField;
use App\Models\Tenant;
use App\Plans\PlanService;
use App\Plans\StorageUsage;
use App\Signing\Support\SealerClient;
use App\Templates\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

/** Troca o selador por um falso que "conta" $pages páginas (ou recusa como PDF inválido). */
function tplSealer(int $pages = 2, ?string $error = null): void
{
    $http = new Http();
    $http->fake(['sealer/v1/fill' => $error === null
        ? Http::response('%PDF-1.4 contado', 200, ['X-Page-Count' => (string) $pages])
        : Http::response(['error' => $error], 422)]);
    app()->instance(SealerClient::class, new SealerClient($http, 'http://sealer', 't'));
}

function tplPdf(int $extra = 0): string
{
    return '%PDF-1.4 modelo'.str_repeat('x', $extra);
}

function tplCreate(?Tenant $t = null, int $pages = 2): DocumentTemplate
{
    tplSealer($pages);
    $t ??= wzOwner()->tenant;

    return app(TemplateService::class)->create($t, 'Contrato de aluguel', tplPdf(), 'aluguel.pdf');
}

function tplField(array $over = []): array
{
    return $over + ['label' => 'Nome do contratante', 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.4, 'height' => 0.04, 'multiline' => false, 'required' => true];
}

it('cria o modelo com o PDF, a contagem de páginas e soma no armazenamento', function () {
    $t = wzOwner()->tenant;
    $before = (int) $t->fresh()->storage_bytes;
    $tpl = tplCreate($t, 3);

    expect($tpl->name)->toBe('Contrato de aluguel')->and($tpl->page_count)->toBe(3)->and($tpl->pdf_size)->toBe(strlen(tplPdf()))
        ->and($tpl->pdf_sha256)->toBe(hash('sha256', tplPdf()))->and($tpl->pdfContents())->toBe(tplPdf())
        ->and(str_starts_with($tpl->pdf_path, "tenants/{$t->id}/templates/"))->toBeTrue()
        ->and((int) $t->fresh()->storage_bytes)->toBe($before + strlen(tplPdf()));
    Storage::disk('local')->assertExists($tpl->pdf_path);
});

it('recusa nome vazio, PDF inválido, protegido, grande demais e fora da cota', function () {
    $t = wzOwner()->tenant;
    $svc = fn () => app(TemplateService::class); // resolvido a cada uso, com o selador falso do momento

    tplSealer();
    expect(fn () => $svc()->create($t, '   ', tplPdf(), 'a.pdf'))->toThrow(FlowException::class, 'nome');
    expect(fn () => $svc()->create($t, 'X', 'isto não é pdf', 'a.pdf'))->toThrow(FlowException::class, 'PDF');

    tplSealer(error: 'invalid_pdf');
    expect(fn () => $svc()->create($t, 'X', tplPdf(), 'a.pdf'))->toThrow(FlowException::class, 'protegido por senha');

    config(['signing.max_pdf_mb' => 1]);
    tplSealer();
    expect(fn () => $svc()->create($t, 'X', '%PDF-'.str_repeat('a', 1024 * 1024 + 10), 'a.pdf'))->toThrow(FlowException::class, '1 MB');

    $small = Tenant::create(['name' => 'Cota']);
    $plan = Plan::create(['name' => 'P', 'slug' => 'p-cota', 'active' => true, 'features' => array_fill_keys(Plan::FEATURES, true), 'limits' => ['storage_mb' => 0]]);
    app(PlanService::class)->assign($small, $plan);
    expect(fn () => $svc()->create($small, 'X', tplPdf(), 'a.pdf'))->toThrow(FlowException::class, 'armazenamento');
    expect(DocumentTemplate::count())->toBe(0);
});

it('sem o selador não cria o modelo e não deixa arquivo nem cota', function () {
    $t = wzOwner()->tenant;
    $http = new Http();
    $http->fake(['sealer/v1/fill' => Http::response('boom', 500)]);
    app()->instance(SealerClient::class, new SealerClient($http, 'http://sealer', 't'));

    expect(fn () => app(TemplateService::class)->create($t, 'X', tplPdf(), 'a.pdf'))->toThrow(FlowException::class, 'indisponível');
    expect(DocumentTemplate::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([])->and((int) $t->fresh()->storage_bytes)->toBe(0);
});

it('grava as lacunas com chave estável e única, na ordem de leitura', function () {
    $tpl = tplCreate();
    app(TemplateService::class)->syncFields($tpl, [
        tplField(['label' => 'Valor', 'page' => 2, 'y' => 0.2]),
        tplField(['label' => 'Nome do contratante', 'y' => 0.5]),
        tplField(['label' => 'Nome do contratante', 'y' => 0.1]),
        tplField(['label' => 'Data', 'y' => 0.1, 'x' => 0.6]),
    ]);

    $fields = $tpl->fields()->get();
    expect($fields->pluck('label')->all())->toBe(['Nome do contratante', 'Data', 'Nome do contratante', 'Valor'])
        ->and($fields->pluck('key')->all())->toBe(['nome_do_contratante_2', 'data', 'nome_do_contratante', 'valor'])
        ->and($fields->pluck('position')->all())->toBe([1, 2, 3, 4]);
});

it('mantém a mesma chave ao regravar a mesma lacuna com o mesmo nome', function () {
    $tpl = tplCreate();
    $svc = app(TemplateService::class);
    $svc->syncFields($tpl, [tplField(['label' => 'Valor']), tplField(['label' => 'Data', 'y' => 0.3])]);
    $first = $tpl->fields()->pluck('key', 'label')->all();
    $svc->syncFields($tpl, [tplField(['label' => 'Valor']), tplField(['label' => 'Data', 'y' => 0.3])]);

    expect($tpl->fields()->pluck('key', 'label')->all())->toBe($first);
});

it('valida lacunas: limite de 40, página, retângulo, tamanho mínimo e nome', function () {
    $tpl = tplCreate(null, 2);
    $svc = app(TemplateService::class);
    $bad = fn (array $over, string $msg) => expect(fn () => $svc->syncFields($tpl, [tplField($over)]))->toThrow(FlowException::class, $msg);

    $bad(['label' => ''], 'nome');
    $bad(['label' => str_repeat('a', 61)], '60');
    $bad(['page' => 3], 'página');
    $bad(['page' => 0], 'página');
    $bad(['x' => 0.8, 'width' => 0.4], 'dentro da página');
    $bad(['y' => -0.1], 'dentro da página');
    $bad(['width' => 0.005], 'pequena');
    $bad(['height' => 0.001], 'pequena');
    expect(fn () => $svc->syncFields($tpl, array_map(fn ($i) => tplField(['label' => "L{$i}", 'y' => 0.01 * $i]), range(1, 41))))->toThrow(FlowException::class, '40');
    expect($tpl->fields()->count())->toBe(0);

    $svc->syncFields($tpl, array_map(fn ($i) => tplField(['label' => "L{$i}", 'y' => 0.02 * $i]), range(1, 40)));
    expect($tpl->fields()->count())->toBe(40);
});

it('lista inválida não apaga as lacunas que já existiam', function () {
    $tpl = tplCreate();
    $svc = app(TemplateService::class);
    $svc->syncFields($tpl, [tplField(['label' => 'Antiga'])]);

    expect(fn () => $svc->syncFields($tpl, [tplField(['label' => 'Boa']), tplField(['page' => 9])]))->toThrow(FlowException::class);
    expect($tpl->fields()->pluck('label')->all())->toBe(['Antiga']);
});

it('trocar o PDF apaga as lacunas, acerta a cota e troca o arquivo', function () {
    $t = wzOwner()->tenant;
    $tpl = tplCreate($t);
    app(TemplateService::class)->syncFields($tpl, [tplField()]);
    $oldPath = $tpl->pdf_path;
    tplSealer(5);

    app(TemplateService::class)->replacePdf($tpl, tplPdf(100), 'novo.pdf');
    $tpl->refresh();

    expect($tpl->fields()->count())->toBe(0)->and($tpl->page_count)->toBe(5)->and($tpl->pdf_size)->toBe(strlen(tplPdf(100)))
        ->and((int) $t->fresh()->storage_bytes)->toBe(strlen(tplPdf(100)));
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($tpl->pdf_path);
});

it('remover apaga lacunas e arquivo e devolve o espaço', function () {
    $t = wzOwner()->tenant;
    $tpl = tplCreate($t);
    app(TemplateService::class)->syncFields($tpl, [tplField()]);
    $path = $tpl->pdf_path;

    app(TemplateService::class)->delete($tpl);

    expect(DocumentTemplate::count())->toBe(0)->and(TemplateField::count())->toBe(0)->and((int) $t->fresh()->storage_bytes)->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('a conciliação noturna do armazenamento conta os PDFs dos modelos', function () {
    $t = wzOwner()->tenant;
    tplCreate($t);

    expect(app(StorageUsage::class)->reconcile($t))->toBe(strlen(tplPdf()))->and((int) $t->fresh()->storage_bytes)->toBe(strlen(tplPdf()));
});

it('a migração deixa modelos em PDF com as colunas novas e o corpo HTML opcional', function () {
    expect(Schema::hasColumns('document_templates', ['pdf_path', 'pdf_sha256', 'pdf_size', 'page_count']))->toBeTrue()
        ->and(Schema::hasTable('template_fields'))->toBeTrue();
    $tpl = tplCreate();
    expect($tpl->body)->toBeNull();
});
