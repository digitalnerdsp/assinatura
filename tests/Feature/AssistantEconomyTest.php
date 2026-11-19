<?php

declare(strict_types=1);

use App\Assistant\Metering\TokenWallet;
use App\Assistant\Pricing\AssistantPricing;
use App\Assistant\Pricing\TokenEconomics;
use App\Models\AssistantTokenEntry;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-12-10 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function aeSuper(): User
{
    $u = User::create(['tenant_id' => Tenant::create(['name' => 'Plataforma '.uniqid()])->id, 'name' => 'Super', 'email' => 'super'.uniqid().'@ae.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['platform_role' => 'super_admin'])->save();

    return $u;
}

function aeSave(): void
{
    app(AssistantPricing::class)->save([
        'base_model' => 'gpt-6-luna', 'brl_per_usd' => '5,90', 'margin_percent' => '100', 'fee_percent' => '0',
        'prices' => [['model' => 'gpt-6-luna', 'input' => '0,10', 'cached' => '0,01', 'output' => '0,50']],
    ]);
}

/** Uma resposta da IA no uso real: debita da carteira com custo e conversa. */
function aeUse(Tenant $t, string $conv, int $in = 6000, int $cached = 4000, int $out = 400): void
{
    app(TokenWallet::class)->debit($t, 'gpt-6-luna', $in, $cached, $out, $conv);
}

function aeTenant(string $name = 'Empresa'): Tenant
{
    $t = Tenant::create(['name' => $name.' '.uniqid()]);
    app(TokenWallet::class)->addPurchase($t, 10_000_000, null, 'teste');

    return $t;
}

// ------------------------------------------------------------------ acesso e tela

it('só o super administrador abre e salva a economia dos tokens', function () {
    $plain = User::create(['tenant_id' => Tenant::create(['name' => 'X'])->id, 'name' => 'D', 'email' => 'd@ae.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $admin = User::create(['tenant_id' => Tenant::create(['name' => 'Y'])->id, 'name' => 'A', 'email' => 'a@ae.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $admin->forceFill(['platform_role' => 'admin'])->save();

    $this->actingAs($plain)->get(route('admin.economy'))->assertNotFound();
    $this->actingAs($admin)->get(route('admin.economy'))->assertForbidden();
    $this->actingAs($admin)->put(route('admin.economy.update'), [])->assertForbidden();
    $this->actingAs(aeSuper())->get(route('admin.economy'))->assertOk()->assertSee('Economia dos tokens');
});

it('sem salvar, a tela mostra os preços iniciais para confirmar e avisa que a fórmula não está ligada', function () {
    $this->actingAs(aeSuper())->get(route('admin.economy'))->assertOk()
        ->assertSee('ainda não está ligada')->assertSee('gpt-6-luna')->assertSee('gpt-5.6-luna')->assertSee('0,10')->assertSee('5,90');
    expect(app(AssistantPricing::class)->configured())->toBeFalse();
});

it('salvar liga a fórmula e a tela mostra o custo e a venda por milhão', function () {
    $super = aeSuper();

    $this->actingAs($super)->put(route('admin.economy.update'), [
        'base_model' => 'gpt-6-luna', 'brl_per_usd' => '5,90', 'margin_percent' => '100', 'fee_percent' => '0',
        'prices' => [['model' => 'gpt-6-luna', 'input' => '0,10', 'cached' => '0,01', 'output' => '0,50'], ['model' => '', 'input' => '', 'cached' => '', 'output' => '']],
    ])->assertRedirect(route('admin.economy'));

    expect(app(AssistantPricing::class)->configured())->toBeTrue();
    $this->actingAs($super)->get(route('admin.economy'))->assertOk()->assertSee('R$ 0,59')->assertSee('R$ 1,18');
});

it('dados inválidos voltam com a explicação e não salvam nada', function () {
    $r = $this->actingAs(aeSuper())->put(route('admin.economy.update'), [
        'base_model' => 'nao-existe', 'brl_per_usd' => '5,90', 'margin_percent' => '100', 'fee_percent' => '0',
        'prices' => [['model' => 'gpt-6-luna', 'input' => '0,10', 'cached' => '', 'output' => '0,50']],
    ]);

    $r->assertSessionHasErrors('economy');
    expect(app(AssistantPricing::class)->configured())->toBeFalse();
});

it('o menu da plataforma tem a economia dos tokens', function () {
    $labels = collect(app(\App\Themes\MenuBuilder::class)->for(aeSuper()))->pluck('label')->all();

    expect($labels)->toContain('Economia dos tokens');
});

// ------------------------------------------------------------------ números reais de uso

it('média de tokens e custo por conversa saem do uso dos últimos 30 dias', function () {
    aeSave();
    $a = aeTenant();
    foreach (['c1', 'c2', 'c3', 'c4', 'c5', 'c6'] as $c) {
        aeUse($a, $c); // 4.400 equivalentes e R$ 0,002596 cada
    }
    aeUse($a, 'c1'); // segunda resposta da conversa c1

    $s = app(TokenEconomics::class)->conversationStats(30);

    expect($s['conversations'])->toBe(6)->and($s['eq_tokens'])->toBe(7 * 4400)->and($s['avg_eq'])->toBe(5133)
        ->and($s['cost_brl'])->toBe(0.018172)->and($s['avg_cost_brl'])->toBe(0.003029);
});

it('uso antigo (mais de 30 dias) e sem conversa não entra na média', function () {
    aeSave();
    $a = aeTenant();
    aeUse($a, 'velha');
    AssistantTokenEntry::query()->where('kind', 'usage')->update(['created_at' => now()->subDays(40)]);
    aeUse($a, 'nova');

    expect(app(TokenEconomics::class)->conversationStats(30)['conversations'])->toBe(1);
});

it('com poucas conversas medidas, usa a estimativa e diz que é estimativa; com 5 ou mais, usa a medida', function () {
    aeSave();
    $e = app(TokenEconomics::class);

    expect($e->tokensPerConversation())->toBe(['value' => 8000, 'measured' => false])
        ->and($e->conversationsLeft(80_000))->toBe(10);

    $a = aeTenant();
    foreach (range(1, 5) as $i) {
        aeUse($a, "m{$i}");
    }

    expect($e->tokensPerConversation())->toBe(['value' => 4400, 'measured' => true])->and($e->conversationsLeft(44_000))->toBe(10);
});

it('o relatório do mês mostra por empresa tokens, custo real e valor de venda equivalente', function () {
    aeSave();
    $a = aeTenant('Alfa');
    $b = aeTenant('Beta');
    aeUse($a, 'a1');
    aeUse($a, 'a2');
    aeUse($b, 'b1');

    $rows = app(TokenEconomics::class)->monthReport('2026-12');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['tenant'])->toBe($a->name)->and($rows[0]['eq_tokens'])->toBe(8800)->and($rows[0]['cost_brl'])->toBe(0.005192)->and($rows[0]['sale_brl'])->toBe(0.010384)
        ->and($rows[1]['tenant'])->toBe($b->name)->and($rows[1]['eq_tokens'])->toBe(4400);
    expect(app(TokenEconomics::class)->monthReport('2026-11'))->toBe([]);
});

it('a tela mostra o relatório do mês escolhido e os totais', function () {
    aeSave();
    $a = aeTenant('Alfa');
    aeUse($a, 'a1');

    $this->actingAs(aeSuper())->get(route('admin.economy', ['mes' => '2026-12']))->assertOk()->assertSee($a->name)->assertSee('4.400');
    $this->actingAs(aeSuper())->get(route('admin.economy', ['mes' => 'lixo']))->assertOk(); // mês inválido volta para o atual
});
