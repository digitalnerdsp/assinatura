<?php

declare(strict_types=1);

use App\Assistant\Metering\TokenWallet;
use App\Assistant\Pricing\AssistantPricing;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-12-10 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function apSave(array $over = []): void
{
    app(AssistantPricing::class)->save($over + [
        'base_model' => 'gpt-6-luna', 'brl_per_usd' => '5,90', 'margin_percent' => '100', 'fee_percent' => '0',
        'prices' => [
            ['model' => 'gpt-6-luna', 'input' => '0,10', 'cached' => '0,01', 'output' => '0,50'],
            ['model' => 'gpt-5.6-luna', 'input' => '0,20', 'cached' => '', 'output' => '1,20'],
        ],
    ]);
}

it('sem salvar a tabela, nada muda: não está configurada e não calcula', function () {
    $p = app(AssistantPricing::class);

    expect($p->configured())->toBeFalse()->and($p->equivalent('gpt-6-luna', 1000, 0, 100))->toBeNull()->and($p->costUsd('gpt-6-luna', 1000, 0, 100))->toBeNull()->and($p->perMillion())->toBeNull();
});

it('os valores iniciais da tela vêm dos preços informados, sem ligar a fórmula', function () {
    $d = app(AssistantPricing::class)->defaults();

    expect($d['base_model'])->toBe('gpt-6-luna')->and($d['brl_per_usd'])->toBe(5.9)->and($d['margin_percent'])->toBe(100.0)
        ->and($d['prices']['gpt-6-luna'])->toBe(['input' => 0.10, 'cached' => 0.01, 'output' => 0.50])
        ->and($d['prices']['gpt-5.6-luna'])->toBe(['input' => 0.20, 'cached' => null, 'output' => 1.20]);
    expect(app(AssistantPricing::class)->configured())->toBeFalse();
});

it('salva a tabela (aceita vírgula) e lê de volta', function () {
    apSave();
    $p = app(AssistantPricing::class);

    expect($p->configured())->toBeTrue()->and($p->baseModel())->toBe('gpt-6-luna')->and($p->fx())->toBe(5.9)->and($p->marginPercent())->toBe(100.0)
        ->and($p->price('gpt-6-luna'))->toBe(['input' => 0.10, 'cached' => 0.01, 'output' => 0.50])
        ->and($p->price('gpt-5.6-luna')['cached'])->toBeNull()->and($p->price('desconhecido'))->toBeNull();
});

it('tokens equivalentes com os preços reais: 6.000 de entrada (4.000 em cache) e 400 de saída = 4.400', function () {
    apSave();

    expect(app(AssistantPricing::class)->equivalent('gpt-6-luna', 6000, 4000, 400))->toBe(4400);
});

it('um modelo mais caro que o base pesa mais; cache desconhecido conta como entrada cheia', function () {
    apSave();

    // ((2000×0,20) + (4000×0,20) + (400×1,20)) / 0,10 = 16.800
    expect(app(AssistantPricing::class)->equivalent('gpt-5.6-luna', 6000, 4000, 400))->toBe(16800);
});

it('o cache nunca passa da entrada e valores negativos viram zero', function () {
    apSave();
    $p = app(AssistantPricing::class);

    expect($p->equivalent('gpt-6-luna', 1000, 5000, 0))->toBe(100) // 1000 em cache × 0,01 / 0,10
        ->and($p->equivalent('gpt-6-luna', 0, 0, 0))->toBe(0);
});

it('custo real em dólar e em reais com o câmbio', function () {
    apSave();
    $p = app(AssistantPricing::class);

    expect($p->costUsd('gpt-6-luna', 6000, 4000, 400))->toBe(0.00044)->and($p->costBrl(0.00044))->toBe(0.002596);
});

it('valor de 1 milhão de tokens equivalentes: custo, e venda com margem e taxas', function () {
    apSave();
    expect(app(AssistantPricing::class)->perMillion())->toBe(['cost_brl' => 0.59, 'sale_brl' => 1.18]);

    apSave(['fee_percent' => '10']);
    expect(app(AssistantPricing::class)->perMillion()['sale_brl'])->toBe(1.298);
});

it('mudar o preço guarda uma nova versão; o histórico e a consulta por data continuam certos', function () {
    apSave();
    CarbonImmutable::setTestNow('2026-12-20 12:00:00');
    apSave(['prices' => [['model' => 'gpt-6-luna', 'input' => '0,08', 'cached' => '0,008', 'output' => '0,40']]]);
    $p = app(AssistantPricing::class);

    expect($p->price('gpt-6-luna')['input'])->toBe(0.08)
        ->and($p->price('gpt-6-luna', CarbonImmutable::parse('2026-12-15'))['input'])->toBe(0.10)
        ->and($p->price('gpt-6-luna', CarbonImmutable::parse('2026-01-01')))->toBeNull() // antes de existir
        ->and($p->price('gpt-5.6-luna'))->not->toBeNull(); // modelo que não veio no formulário continua
    expect(count($p->versions('gpt-6-luna')))->toBe(2);

    apSave(['prices' => [['model' => 'gpt-6-luna', 'input' => '0,08', 'cached' => '0,008', 'output' => '0,40']]]); // igual: sem versão nova
    expect(count($p->versions('gpt-6-luna')))->toBe(2);
});

it('recusa preço inválido e modelo base sem preço', function () {
    expect(fn () => apSave(['prices' => [['model' => 'm', 'input' => '-1', 'cached' => '', 'output' => '1']]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => apSave(['prices' => [['model' => 'm', 'input' => '0', 'cached' => '', 'output' => '1']]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => apSave(['prices' => [['model' => 'm!', 'input' => '1', 'cached' => '', 'output' => '1']]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => apSave(['base_model' => 'sem-preco']))->toThrow(InvalidArgumentException::class);
    expect(fn () => apSave(['brl_per_usd' => '0']))->toThrow(InvalidArgumentException::class);
    expect(fn () => apSave(['margin_percent' => '-5']))->toThrow(InvalidArgumentException::class);
    expect(app(AssistantPricing::class)->configured())->toBeFalse();
});

it('a carteira usa a fórmula quando o modelo tem preço e o cálculo antigo quando não tem', function () {
    Setting::put('assistant.model_weights', json_encode(['antigo' => 2]));
    Setting::put('assistant.output_factor', '4');
    Setting::put('assistant.cached_factor', '0.1');
    $w = app(TokenWallet::class);

    expect($w->equivalent('antigo', 1000, 0, 100))->toBe(2800); // (1000 + 100×4) × 2: ainda o cálculo antigo

    apSave();
    expect($w->equivalent('gpt-6-luna', 6000, 4000, 400))->toBe(4400)
        ->and($w->equivalent('antigo', 1000, 0, 100))->toBe(2800); // modelo sem preço continua no cálculo antigo
});
