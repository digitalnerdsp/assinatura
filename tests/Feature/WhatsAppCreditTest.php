<?php

declare(strict_types=1);

use App\Assistant\Metering\MetaUsageLedger;
use App\Assistant\Metering\WhatsAppCredit;
use App\Models\MetaMessageUsage;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-12-10 12:00:00');
    Setting::put('assistant.markup_percent', '25');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function wcTenant(array $attrs = []): Tenant
{
    return Tenant::create(['name' => 'Empresa '.uniqid()] + $attrs);
}

function wcUse(Tenant $t, string $id, float $meta = 0.04, bool $billable = true): void
{
    app(MetaUsageLedger::class)->record($t, $id, 'utility', $billable, $meta, 'BRL', CarbonImmutable::now());
}

it('o pré-pago é o padrão e começa com saldo zero', function () {
    $t = wcTenant()->refresh();

    expect(app(WhatsAppCredit::class)->isPostpaid($t))->toBeFalse()
        ->and(app(WhatsAppCredit::class)->balance($t))->toBe(0.0)
        ->and(app(WhatsAppCredit::class)->canAfford($t, 0.05))->toBeFalse();
});

it('recarga soma, mensagem cobrada debita o valor da Meta com o acréscimo, uma vez só', function () {
    $t = wcTenant();
    $c = app(WhatsAppCredit::class);
    $c->topUp($t, 10.0, 'Pix de teste');

    wcUse($t, 'wamid.A');
    wcUse($t, 'wamid.A'); // o mesmo aviso da Meta repetido

    expect(MetaMessageUsage::query()->count())->toBe(1)
        ->and($c->balance($t))->toBe(9.95) // 10 − 0,04 × 1,25
        ->and($c->canAfford($t, 9.95))->toBeTrue()
        ->and($c->canAfford($t, 9.96))->toBeFalse();
});

it('mensagem que a Meta não cobra não debita nada', function () {
    $t = wcTenant();
    app(WhatsAppCredit::class)->topUp($t, 1.0, 'x');

    wcUse($t, 'wamid.F', 0.0, false);

    expect(app(WhatsAppCredit::class)->balance($t))->toBe(1.0);
});

it('o pós-pago pode ficar negativo até o limite e a quitação zera a dívida', function () {
    $t = wcTenant();
    wcPostpaid($t, 0.10);
    $c = app(WhatsAppCredit::class);

    expect($c->canAfford($t, 0.05))->toBeTrue()->and($c->canAfford($t, 0.11))->toBeFalse();

    wcUse($t, 'wamid.P1'); // 0,05
    wcUse($t, 'wamid.P2'); // 0,05

    expect($c->balance($t))->toBe(-0.1)->and($c->available($t))->toBe(0.0)->and($c->canAfford($t, 0.05))->toBeFalse();

    $c->settle($t, 0.10, 'Cobrado na mensalidade de dezembro');

    expect($c->balance($t))->toBe(0.0)->and($c->canAfford($t, 0.05))->toBeTrue();
});

it('a empresa do super administrador não é debitada (a plataforma absorve)', function () {
    $t = wcTenant();
    $u = User::create(['tenant_id' => $t->id, 'name' => 'Super', 'email' => 'super-wc@x.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['platform_role' => 'super_admin'])->save();

    wcUse($t, 'wamid.S');

    expect($t->isPlatformOwned())->toBeTrue()
        ->and(MetaMessageUsage::query()->count())->toBe(1)
        ->and(app(WhatsAppCredit::class)->balance($t))->toBe(0.0)
        ->and(app(WhatsAppCredit::class)->canAfford($t, 99.0))->toBeTrue();
});

it('o extrato do mês lista recargas e usos', function () {
    $t = wcTenant();
    $c = app(WhatsAppCredit::class);
    $c->topUp($t, 5.0, 'Pix');
    wcUse($t, 'wamid.E');

    $m = $c->monthSummary($t, '2026-12');

    expect($m['topups'])->toBe(5.0)->and($m['usage'])->toBe(0.05)->and($m['messages'])->toBe(1);
    expect($c->entries($t)->pluck('kind')->all())->toContain('topup', 'usage');
});

it('valores inválidos de lançamento são recusados', function () {
    $t = wcTenant();

    expect(fn () => app(WhatsAppCredit::class)->topUp($t, 0.0, 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(WhatsAppCredit::class)->topUp($t, -3.0, 'x'))->toThrow(InvalidArgumentException::class);
});

/** Pós-pago só desta empresa: sobrescrita da assinatura (o plano é quem normalmente define). */
function wcPostpaid(Tenant $t, float $limit): void
{
    app(\App\Plans\PlanService::class)->subscriptionFor($t)->forceFill(['overrides' => ['limits' => ['wa_credit_limit_cents' => (int) round($limit * 100)]]])->save();
}
