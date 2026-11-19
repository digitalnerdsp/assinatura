<?php

declare(strict_types=1);

use App\Assistant\Metering\TokenWallet;
use App\Assistant\Pricing\AssistantPricing;
use App\Models\AssistantTokenEntry;
use App\Models\Setting;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-12-10 12:00:00');
    Setting::put('assistant.overrun_cap', '0');
});
afterEach(fn () => CarbonImmutable::setTestNow());

function acPrices(string $input = '0,10', string $cached = '0,01', string $output = '0,50', string $fx = '5,90'): void
{
    app(AssistantPricing::class)->save([
        'base_model' => 'gpt-6-luna', 'brl_per_usd' => $fx, 'margin_percent' => '100', 'fee_percent' => '0',
        'prices' => [['model' => 'gpt-6-luna', 'input' => $input, 'cached' => $cached, 'output' => $output]],
    ]);
}

function acTenant(int $tokens = 100000): Tenant
{
    $t = Tenant::create(['name' => 'Empresa AC '.uniqid()]);
    app(TokenWallet::class)->addPurchase($t, $tokens, null, 'teste');

    return $t;
}

it('cada resposta guarda o custo real em dólar, o câmbio e o custo em reais', function () {
    acPrices();
    $t = acTenant();

    app(TokenWallet::class)->debit($t, 'gpt-6-luna', 6000, 4000, 400, 'conv-1');

    $e = AssistantTokenEntry::query()->where('kind', 'usage')->first();
    expect((float) $e->cost_usd)->toBe(0.00044)->and((float) $e->fx)->toBe(5.9)->and((float) $e->cost_brl)->toBe(0.002596)->and($e->tokens)->toBe(-4400);
});

it('mudar o preço ou o câmbio depois não altera o custo já gravado', function () {
    acPrices();
    $t = acTenant();
    app(TokenWallet::class)->debit($t, 'gpt-6-luna', 6000, 4000, 400, 'conv-1');

    CarbonImmutable::setTestNow('2026-12-20 12:00:00');
    acPrices('0,50', '0,05', '2,50', '7,00');

    expect((float) AssistantTokenEntry::query()->where('kind', 'usage')->first()->cost_brl)->toBe(0.002596);

    app(TokenWallet::class)->debit($t, 'gpt-6-luna', 6000, 4000, 400, 'conv-2'); // o novo uso já sai com o preço novo
    $new = AssistantTokenEntry::query()->where('kind', 'usage')->orderByDesc('created_at')->first();
    expect((float) $new->cost_usd)->toBe(0.0022)->and((float) $new->fx)->toBe(7.0);
});

it('modelo sem preço grava custo nulo e continua debitando pelo cálculo antigo', function () {
    Setting::put('assistant.output_factor', '4');
    Setting::put('assistant.cached_factor', '0.1');
    acPrices();
    $t = acTenant();

    app(TokenWallet::class)->debit($t, 'modelo-sem-preco', 1000, 0, 100, null);

    $e = AssistantTokenEntry::query()->where('kind', 'usage')->first();
    expect($e->cost_usd)->toBeNull()->and($e->cost_brl)->toBeNull()->and($e->tokens)->toBe(-1400);
});

it('uma resposta paga por mais de uma concessão grava o custo uma vez só', function () {
    acPrices();
    $t = Tenant::create(['name' => 'Duas concessões']);
    app(TokenWallet::class)->addPurchase($t, 1000, null, 'a');
    app(TokenWallet::class)->addPurchase($t, 5000, null, 'b');

    app(TokenWallet::class)->debit($t, 'gpt-6-luna', 6000, 4000, 400, 'conv-9'); // 4.400 equivalentes: sai das duas

    $rows = AssistantTokenEntry::query()->where('kind', 'usage')->get();
    expect($rows->count())->toBeGreaterThan(1)->and((float) $rows->sum('cost_brl'))->toBe(0.002596);
});

it('sem a tabela salva, nada é gravado no custo e o débito segue como antes', function () {
    $t = acTenant();
    Setting::put('assistant.output_factor', '4');
    Setting::put('assistant.cached_factor', '0.1');

    app(TokenWallet::class)->debit($t, 'qualquer', 1000, 0, 100, null);

    $e = AssistantTokenEntry::query()->where('kind', 'usage')->first();
    expect($e->cost_brl)->toBeNull()->and($e->tokens)->toBe(-1400);
});
