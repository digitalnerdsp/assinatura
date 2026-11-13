<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageEntry;
use App\Models\User;
use App\Plans\Entitlements;
use App\Plans\PlanService;
use App\Team\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['platform.super_admins' => ['raiz@plataforma.com']]);
});

function rf3Tenant(array $plan = []): Tenant
{
    static $n = 0;
    $n++;
    $p = Plan::create($plan + ['name' => 'R'.$n, 'slug' => 'r-'.$n, 'active' => true, 'features' => array_fill_keys(Plan::FEATURES, true)]);
    $t = Tenant::create(['name' => 'Empresa '.$n]);
    app(PlanService::class)->assign($t, $p);

    return $t->fresh();
}

function rf3Send(Tenant $t): \App\Models\Envelope
{
    static $k = 0;
    $k++;
    $e = app(EnvelopeService::class)->create($t, null, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'Doc '.$k, [['name' => 'Ana', 'email' => "ana{$k}@ex.com"]]);
    app(EnvelopeService::class)->send($e);

    return $e->fresh();
}

function rf3Reason(Closure $fn): ?string
{
    try {
        $fn();
    } catch (FlowException $e) {
        return $e->reason;
    }

    return null;
}

it('desconto ao concluir: documentos enviados e ainda abertos já ocupam a cota (não dá para enviar 500 com cota 2)', function () {
    $t = rf3Tenant(['limits' => ['documents' => 2], 'consumption' => ['when' => 'complete']]);

    rf3Send($t);
    rf3Send($t);
    expect($t->fresh()->subscription->quota_used)->toBe(0)
        ->and(Entitlements::for($t)->canSendDocument()->code)->toBe('documents_limit')
        ->and(rf3Reason(fn () => rf3Send($t)))->toBe('documents_limit');
});

it('não dá para desativar nem apagar o plano padrão, e a tela avisa quando só o Legado serve de padrão', function () {
    $su = User::create(['tenant_id' => Tenant::create(['name' => 'Plataforma'])->id, 'name' => 'Raiz', 'email' => 'raiz@plataforma.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $this->actingAs($su);
    $padrao = Plan::create(['name' => 'Padrão', 'slug' => 'padrao', 'active' => true, 'is_default' => true]);

    $this->put('/admin/planos/'.$padrao->id, ['name' => 'Padrão', 'position' => 0, 'is_public' => 1])->assertSessionHasErrors('plan'); // sem "active"
    $this->delete('/admin/planos/'.$padrao->id)->assertSessionHasErrors('plan');
    expect($padrao->fresh()->active)->toBeTrue()->and($padrao->fresh()->is_default)->toBeTrue();

    $padrao->update(['is_default' => false]);
    $this->get('/admin/planos')->assertOk()->assertSee('Nenhum plano padrão ativo');
});

it('reativar um usuário também respeita o limite de usuários do plano', function () {
    $t = rf3Tenant(['limits' => ['users' => 2]]);
    $owner = User::create(['tenant_id' => $t->id, 'name' => 'Dono', 'email' => 'dono@x.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $svc = app(TeamService::class);

    [$b] = $svc->invite($owner, 'B', 'b@x.com');
    $svc->deactivate($owner, $b);
    $svc->invite($owner, 'C', 'c@x.com'); // dono + C = 2

    expect(rf3Reason(fn () => $svc->reactivate($owner, $b->fresh())))->toBe('users_limit')
        ->and($b->fresh()->isActive())->toBeFalse();
});

it('o teto de excedente conta só envios acima da cota: ajuste manual com a palavra "excedente" não conta e devolução abate', function () {
    $t = rf3Tenant(['limits' => ['documents' => 1], 'consumption' => ['on_empty' => 'cap', 'cap' => 3, 'refund_unsigned' => true]]);
    rf3Send($t);                // cota
    rf3Send($t);                // excedente 1
    rf3Send($t);                // excedente 2
    UsageEntry::create(['tenant_id' => $t->id, 'kind' => 'adjust', 'delta' => 5, 'balance_after' => 5, 'note' => 'pagamento do excedente de outubro', 'occurred_at' => now()]);

    expect(Entitlements::for($t)->overageUsed())->toBe(2);
    $d = rf3Send($t);           // excedente 3 (ainda dentro do teto)
    expect(rf3Reason(fn () => rf3Send($t)))->toBe('documents_limit');

    app(EnvelopeService::class)->cancel($d->fresh()); // devolve: abate do teto
    expect(Entitlements::for($t)->overageUsed())->toBe(2);
    rf3Send($t);                // volta a caber
});

it('a rotina de Legado cobre todas as empresas mesmo com mais de mil sem assinatura', function () {
    $rows = [];
    for ($i = 0; $i < 1105; $i++) {
        $rows[] = ['id' => (string) Str::uuid7(), 'name' => 'T'.$i, 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 300) as $chunk) {
        DB::table('tenants')->insert($chunk);
    }

    app(PlanService::class)->backfillLegacy();

    expect(Tenant::whereDoesntHave('subscription')->count())->toBe(0)
        ->and(Subscription::count())->toBe(1105);
});
