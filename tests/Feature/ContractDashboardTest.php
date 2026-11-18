<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-11-18 15:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

it('o painel mostra o cartão com os contratos a vencer, do mais próximo ao mais distante', function () {
    $u = wzOwner();
    ctDue($u, 20, 'Vence em vinte');
    ctDue($u, 3, 'Vence em três');
    ctDue($u, 200, 'Contrato distante');

    $res = $this->actingAs($u)->get('/painel')->assertOk()->assertSee('Contratos a vencer')->assertSee('Vence em três')->assertSee('3 dias')->assertSee('20 dias');
    expect($res->viewData('contracts')['items']->pluck('title')->all())->toBe(['Vence em três', 'Vence em vinte'])
        ->and($res->viewData('contracts')['soon'])->toBe(2);
});

it('mostra no máximo 5 contratos mas conta todos os que estão a vencer', function () {
    $u = wzOwner();
    foreach (range(1, 7) as $i) {
        ctDue($u, $i, "C{$i}");
    }

    $c = $this->actingAs($u)->get('/painel')->viewData('contracts');
    expect($c['items'])->toHaveCount(5)->and($c['soon'])->toBe(7);
});

it('o aviso aparece quando há contrato vencido ou vencendo em até 7 dias', function () {
    $u = wzOwner();
    ctDue($u, 20, 'Tranquilo');
    $this->actingAs($u)->get('/painel')->assertOk()->assertDontSee('precisam de atenção');

    ctDue($u, -2, 'Já venceu');
    ctDue($u, 7, 'Semana');
    ctDue($u, 8, 'Depois da semana');
    $res = $this->actingAs($u)->get('/painel')->assertOk()->assertSee('2 contratos precisam de atenção');
    expect($res->viewData('contracts')['attention'])->toBe(2);
});

it('sem contratos monitorados o cartão explica como começar e não há aviso', function () {
    $u = wzOwner();
    $this->actingAs($u)->get('/painel')->assertOk()->assertSee('Contratos a vencer')->assertSee('Nenhum contrato a vencer')->assertDontSee('precisam de atenção');
});

it('não conta contratos de outra empresa nem de rascunhos e lixeira', function () {
    $u = wzOwner();
    $other = wzOwner('outro@wz.test');
    ctDue($other, 2, 'Alheio');
    ctDue($u, 2, 'Na lixeira')->update(['trashed_at' => now()]);
    ctDue($u, 3, 'Em andamento')->update(['status' => 'in_progress']);

    $c = $this->actingAs($u)->get('/painel')->viewData('contracts');
    expect($c['soon'])->toBe(0)->and($c['attention'])->toBe(0);
});
