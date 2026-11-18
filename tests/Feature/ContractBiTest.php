<?php

declare(strict_types=1);

use App\Contracts\ContractWatch;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Plan;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-11-18 15:00:00', 'UTC')); // 18/11/2026 em Brasília
});

afterEach(fn () => Carbon::setTestNow());

/** Contrato concluído que vence daqui a $days dias (negativo = já venceu). Grava a data direto: o serviço só aceita datas futuras. */
function ctDue(User $u, int $days, string $title, int $alertDays = 30): Envelope
{
    $e = ctDoc($u, 'completed', $title);
    $e->forceFill([
        'contract_expires_on' => Carbon::now('America/Sao_Paulo')->startOfDay()->addDays($days)->toDateString(),
        'contract_alert_days' => $alertDays,
        'contract_alert_emails' => ['gestor@wz.test'],
    ])->save();

    return $e->refresh();
}

function ctTitles($response): array
{
    return collect($response->viewData('rows')->items())->pluck('title')->all();
}

it('conta cada faixa nos limites e o menu leva à tela', function () {
    $u = wzOwner();
    foreach ([[-5, 'Vencido'], [0, 'Hoje'], [30, 'D30'], [31, 'D31'], [60, 'D60'], [61, 'D61'], [90, 'D90'], [91, 'D91'], [400, 'Longe']] as [$d, $t]) {
        ctDue($u, $d, $t);
    }

    $res = $this->actingAs($u)->get('/contratos')->assertOk()->assertSee('Contratos')->assertSee(route('contracts.index'), false);
    expect($res->viewData('counts'))->toMatchArray(['overdue' => 1, 'd30' => 2, 'd60' => 2, 'd90' => 2, 'valid' => 2, 'soon' => 3]);
});

it('o padrão a vencer mostra vencidos e a janela de 30 dias, ampliada pelo aviso escolhido', function () {
    $u = wzOwner();
    ctDue($u, -3, 'Vencido');
    ctDue($u, 10, 'Em 10 dias');
    ctDue($u, 45, 'Em 45, aviso 30', 30);
    ctDue($u, 45, 'Em 45, aviso 60', 60);
    ctDue($u, 80, 'Em 80, aviso 90', 90);
    ctDue($u, 200, 'Longe');

    $titles = ctTitles($this->actingAs($u)->get('/contratos')->assertOk());

    expect($titles)->toBe(['Vencido', 'Em 10 dias', 'Em 45, aviso 60', 'Em 80, aviso 90']);
});

it('cada faixa filtra a lista e a ordem é pela data de vencimento', function (string $faixa, array $expected) {
    $u = wzOwner();
    foreach ([[-5, 'Vencido'], [20, 'D20'], [30, 'D30'], [45, 'D45'], [75, 'D75'], [150, 'D150'], [10, 'D10']] as [$d, $t]) {
        ctDue($u, $d, $t);
    }

    expect(ctTitles($this->actingAs($u)->get('/contratos?faixa='.$faixa)->assertOk()))->toBe($expected);
})->with([
    'vencidos' => ['vencidos', ['Vencido']],
    'ate30' => ['ate30', ['D10', 'D20', 'D30']],
    '31a60' => ['31a60', ['D45']],
    '61a90' => ['61a90', ['D75']],
    'vigentes' => ['vigentes', ['D150']],
    'todos' => ['todos', ['Vencido', 'D10', 'D20', 'D30', 'D45', 'D75', 'D150']],
]);

it('filtra por pasta, etiqueta e texto', function () {
    $u = wzOwner();
    $f = Folder::create(['tenant_id' => $u->tenant_id, 'name' => 'Jurídico']);
    $tag = Tag::create(['tenant_id' => $u->tenant_id, 'name' => 'Aluguel', 'color' => 'info']);
    $a = ctDue($u, 10, 'Aluguel da sede');
    $b = ctDue($u, 12, 'Contrato de TI');
    $a->update(['folder_id' => $f->id]);
    $a->tags()->sync([$tag->id]);

    expect(ctTitles($this->actingAs($u)->get('/contratos?folder='.$f->id)))->toBe(['Aluguel da sede']);
    expect(ctTitles($this->actingAs($u)->get('/contratos?tag='.$tag->id)))->toBe(['Aluguel da sede']);
    expect(ctTitles($this->actingAs($u)->get('/contratos?q=ti')))->toBe(['Contrato de TI']);
    expect(ctTitles($this->actingAs($u)->get('/contratos?q=zzz')))->toBe([]);
    expect($b->fresh()->title)->toBe('Contrato de TI');
});

it('só mostra concluídos, não arquivados na lixeira, e nunca de outra empresa', function () {
    $u = wzOwner();
    $other = wzOwner('outro@wz.test');
    ctDue($u, 10, 'Meu');
    ctDue($other, 10, 'Alheio');
    $going = ctDue($u, 11, 'Em andamento');
    $going->update(['status' => 'in_progress']);
    $trashed = ctDue($u, 12, 'Na lixeira');
    $trashed->update(['trashed_at' => now()]);
    $archived = ctDue($u, 13, 'Arquivado');
    $archived->update(['archived_at' => now()]);
    $noTerm = ctDoc($u, 'completed', 'Sem vigência');

    $titles = ctTitles($this->actingAs($u)->get('/contratos?faixa=todos'));
    expect($titles)->toBe(['Meu', 'Arquivado'])->and($noTerm->fresh()->contract_expires_on)->toBeNull();
});

it('mostra os dias restantes e a cor de cada contrato', function () {
    $u = wzOwner();
    ctDue($u, -2, 'Venceu');
    ctDue($u, 0, 'Hoje');
    ctDue($u, 5, 'Cinco');

    $html = $this->actingAs($u)->get('/contratos')->assertOk()->getContent();
    expect($html)->toContain('Vencido há 2 dias')->and($html)->toContain('Vence hoje')->and($html)->toContain('5 dias');
    $this->actingAs($u)->get('/contratos')->assertSee('#vigencia', false);
});

it('o gráfico traz os próximos 12 meses com a contagem de cada mês', function () {
    $u = wzOwner();
    ctDue($u, 10, 'Nov');   // 28/11/2026
    ctDue($u, 20, 'Dez A'); // 08/12/2026
    ctDue($u, 25, 'Dez B'); // 13/12/2026
    ctDue($u, 120, 'Mar');  // 18/03/2027
    ctDue($u, 800, 'Fora do ano');
    ctDue($u, -3, 'Vencido');

    $chart = $this->actingAs($u)->get('/contratos?faixa=todos')->viewData('chart');

    expect($chart['labels'])->toHaveCount(12)->and($chart['labels'][0])->toBe(Carbon::now('America/Sao_Paulo')->startOfMonth()->locale('pt_BR')->isoFormat('MMM/YY'))->and($chart['values'])->toHaveCount(12)
        ->and($chart['values'][0])->toBe(1)->and($chart['values'][1])->toBe(2)->and($chart['values'][4])->toBe(1)->and(array_sum($chart['values']))->toBe(4);
});

it('exporta o CSV com os mesmos filtros, BOM e células seguras', function () {
    $u = wzOwner();
    ctDue($u, 10, '=SOMA(1+1)');
    ctDue($u, 150, 'Longe');

    $res = $this->actingAs($u)->get('/contratos/exportar?faixa=avencer')->assertOk();
    $csv = $res->streamedContent();

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()->and($csv)->toContain('Título;Pasta;Etiquetas;Vencimento;Dias restantes;Faixa;Avisar (dias antes);E-mails do aviso')
        ->and($csv)->toContain("'=SOMA(1+1)")->and($csv)->not->toContain('Longe')->and($csv)->toContain('gestor@wz.test')->and($res->headers->get('content-disposition'))->toContain('contratos-vigencia.csv');
});

it('plano sem o recurso de relatórios não vê a tela nem exporta', function () {
    $t = Tenant::create(['name' => 'Sem relatórios']);
    $p = Plan::create(['name' => 'P', 'slug' => 'p-ct', 'active' => true, 'features' => array_fill_keys(Plan::FEATURES, true)]);
    app(PlanService::class)->assign($t, $p)->update(['overrides' => ['features' => ['reports' => false]]]);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'Dono', 'email' => 'sem@wz.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);

    $this->actingAs($u)->get('/contratos')->assertForbidden();
    $this->actingAs($u)->get('/contratos/exportar')->assertForbidden();
});

it('exige entrar na conta e valida os filtros', function () {
    $this->get('/contratos')->assertRedirect('/entrar');
    $u = wzOwner();
    $this->actingAs($u)->get('/contratos?faixa=inventada')->assertSessionHasErrors('faixa');
    $this->actingAs($u)->get('/contratos?folder=nao-e-uuid')->assertSessionHasErrors('folder');
});

it('o menu lateral tem o item Contratos', function () {
    $u = wzOwner();
    $this->actingAs($u)->get('/painel')->assertOk()->assertSee('Contratos');
    $menu = collect((new \App\Themes\MenuBuilder())->for($u))->pluck('label')->all();
    expect($menu)->toContain('Contratos');
});

it('a tela vazia explica como começar', function () {
    $u = wzOwner();
    $this->actingAs($u)->get('/contratos')->assertOk()->assertSee('Nenhum contrato')->assertSee('último passo');
});
