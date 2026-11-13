<?php

declare(strict_types=1);

use App\Folders\FolderService;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\TenantSigningSetting;
use App\Models\User;
use App\Reports\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function reportDoc(Tenant $t, string $status, array $extra = []): Envelope
{
    $doc = Document::create(['tenant_id' => $t->id, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);

    return Envelope::create($extra + [
        'tenant_id' => $t->id, 'document_id' => $doc->id, 'title' => 'Doc '.$status, 'status' => $status,
        'verification_code' => strtoupper(Str::random(12)),
    ]);
}

function reportUser(): User
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', 'dono@acme.com')->firstOrFail();
}

it('conta os documentos por situação, sem a lixeira', function () {
    $t = Tenant::create(['name' => 'ACME']);
    foreach (['completed', 'completed', 'sent', 'in_progress', 'sealing', 'refused', 'expired', 'cancelled', 'draft'] as $s) {
        reportDoc($t, $s);
    }
    reportDoc($t, 'draft', ['trashed_at' => now()]);

    $s = app(ReportService::class)->summary($t, []);

    expect($s)->toMatchArray(['total' => 9, 'completed' => 2, 'in_progress' => 3, 'refused' => 1, 'expired' => 1, 'cancelled' => 1]);
});

it('calcula o tempo médio e as faixas só com concluídos que têm envio', function () {
    $t = Tenant::create(['name' => 'ACME']);
    $base = now()->subDays(20);
    foreach ([600, 7200, 3 * 86400, 12 * 86400] as $seconds) {
        reportDoc($t, 'completed', ['sent_at' => $base, 'completed_at' => $base->copy()->addSeconds($seconds)]);
    }
    reportDoc($t, 'completed', ['sent_at' => null, 'completed_at' => now()]); // sem envio: fora da média
    reportDoc($t, 'in_progress', ['sent_at' => $base]);

    $s = app(ReportService::class)->summary($t, []);

    expect($s['buckets'])->toBe(['30m' => 1, '1h' => 0, '24h' => 1, '2d' => 0, '5d' => 1, '10d' => 0, 'more' => 1])
        ->and($s['avg_seconds'])->toBe((int) round((600 + 7200 + 3 * 86400 + 12 * 86400) / 4));
});

it('aplica os filtros de período, pasta com subpastas, etiqueta, modelo e signatário', function () {
    $t = Tenant::create(['name' => 'ACME']);
    $svc = app(FolderService::class);
    $a = $svc->create($t, 'A');
    $a1 = $svc->create($t, 'A1', $a);
    $tag = Tag::create(['tenant_id' => $t->id, 'name' => 'Urgente', 'color' => 'danger']);
    $tpl = DocumentTemplate::create(['tenant_id' => $t->id, 'name' => 'Modelo', 'body' => '<p>x</p>']);

    $old = reportDoc($t, 'completed', ['created_at' => '2026-10-01 12:00:00']);
    $inSub = reportDoc($t, 'completed', ['folder_id' => $a1->id, 'template_id' => $tpl->id, 'created_at' => '2026-11-10 12:00:00']);
    $inSub->tags()->attach($tag->id);
    Signer::create(['envelope_id' => $inSub->id, 'name' => 'Ana', 'email' => 'ana@ex.com']);
    reportDoc($t, 'draft', ['created_at' => '2026-11-12 12:00:00']);

    $r = fn (array $f) => app(ReportService::class)->summary($t, $f)['total'];
    expect($r(['from' => '2026-11-01']))->toBe(2)
        ->and($r(['to' => '2026-10-31']))->toBe(1)
        ->and($r(['folder' => $a->id]))->toBe(1)
        ->and($r(['tag' => $tag->id]))->toBe(1)
        ->and($r(['template' => $tpl->id]))->toBe(1)
        ->and($r(['signer' => 'ANA@ex.com']))->toBe(1);

    $other = Tenant::create(['name' => 'Outra']);
    $foreignFolder = $svc->create($other, 'Alheia');
    $foreignTag = Tag::create(['tenant_id' => $other->id, 'name' => 'X', 'color' => 'info']);
    expect($r(['folder' => $foreignFolder->id]))->toBe(0)->and($r(['tag' => $foreignTag->id]))->toBe(0);
    expect($old->exists)->toBeTrue();
});

it('a tela mostra indicadores e faixas, e exporta CSV com proteção contra fórmula', function () {
    $user = reportUser();
    reportDoc($user->tenant, 'completed', ['title' => '=HACK()', 'sent_at' => now()->subHour(), 'completed_at' => now()]);

    $this->get('/relatorios')->assertOk()->assertSee('Tempo médio de assinatura')->assertSee('< 30 min')->assertSee('10 dias ou mais');

    $csv = $this->get('/relatorios/exportar')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($csv)->toContain('Título;Situação;Criado em;Enviado em;Concluído em;Tempo até concluir;Pasta;Etiquetas;Signatários')
        ->toContain("'=HACK()");
});

it('a tela de relatório recusa filtros inválidos sem erro e não mostra dados de outra empresa', function () {
    reportUser();
    reportDoc(Tenant::create(['name' => 'Outra']), 'completed', ['title' => 'Segredo alheio']);

    $this->get('/relatorios?from=ontem&folder=nao-e-uuid')->assertSessionHasErrors();
    $this->get('/relatorios')->assertOk()->assertDontSee('Segredo alheio');
});

it('o Painel usa os mesmos números do relatório', function () {
    $user = reportUser();
    foreach (['completed', 'sent', 'in_progress', 'refused', 'expired', 'draft'] as $s) {
        reportDoc($user->tenant, $s);
    }
    $summary = app(ReportService::class)->summary($user->tenant, []);

    $this->get('/painel')->assertOk()->assertViewHas('kpi', fn ($k) => $k['total'] === $summary['total']
        && $k['pending'] === $summary['in_progress']
        && $k['failed'] === $summary['refused'] + $summary['expired'] + $summary['cancelled']);
});

it('gerar documento por modelo grava o modelo no envelope', function () {
    Storage::fake('local');
    $user = reportUser();
    TenantSigningSetting::updateOrCreate(['tenant_id' => $user->tenant_id], ['sender_policy' => 'never']);
    $this->post('/modelos', ['name' => 'Contrato', 'body' => '<p>Olá {{nome}}</p>'])->assertRedirect();
    $tpl = DocumentTemplate::firstOrFail();

    $this->post("/modelos/{$tpl->id}/usar", [
        'title' => 'Gerado', 'signing_order' => 'parallel', 'vars' => ['nome' => 'Ana'],
        'signers' => [['name' => 'Ana', 'email' => 'ana@ex.com']],
    ])->assertRedirect();

    expect(Envelope::where('title', 'Gerado')->firstOrFail()->template_id)->toBe($tpl->id);
});
