<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Models\ApiKey;
use App\Models\Envelope;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\UsageEntry;
use App\Models\User;
use App\Plans\Entitlements;
use App\Plans\PlanService;
use App\Plans\UsageLedger;
use App\Team\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

/** Empresa com plano próprio. $plan = atributos do plano; $sub = atributos da assinatura. */
function entTenant(array $plan = [], array $sub = []): Tenant
{
    static $n = 0;
    $n++;
    $p = Plan::create($plan + ['name' => 'P'.$n, 'slug' => 'p-'.$n, 'active' => true]);
    $t = Tenant::create(['name' => 'Empresa '.$n]);
    $s = app(PlanService::class)->assign($t, $p);
    if ($sub !== []) {
        $s->forceFill($sub)->save();
    }

    return $t->fresh();
}

function entDraft(Tenant $t, string $title = 'Doc'): Envelope
{
    static $k = 0;
    $k++;

    return app(EnvelopeService::class)->create($t, null, "%PDF-1.4\n%%EOF\n", 'a.pdf', $title.' '.$k, [['name' => 'Ana', 'email' => "ana{$k}@ex.com"]]);
}

function entSend(Envelope $e): array
{
    return app(EnvelopeService::class)->send($e);
}

function reasonOf(Closure $fn): ?string
{
    try {
        $fn();
    } catch (FlowException $e) {
        return $e->reason;
    }

    return null;
}

it('o plano Legado não muda nada: envia sem limite e sem lançar extrato', function () {
    $t = Tenant::create(['name' => 'Antiga']);
    app(PlanService::class)->backfillLegacy();

    foreach (range(1, 4) as $i) {
        entSend(entDraft($t));
    }

    expect(Envelope::where('tenant_id', $t->id)->where('status', 'sent')->count())->toBe(4)->and(UsageEntry::count())->toBe(0)
        ->and(Entitlements::for($t)->canSendDocument()->allowed)->toBeTrue();
});

it('a cota do período bloqueia só o envio (rascunho continua livre) e o rascunho fica intacto', function () {
    $t = entTenant(['limits' => ['documents' => 2]]);

    entSend(entDraft($t));
    entSend(entDraft($t));
    $terceiro = entDraft($t); // criar rascunho não conta

    expect(reasonOf(fn () => entSend($terceiro)))->toBe('documents_limit');
    expect($terceiro->fresh()->status->value)->toBe('draft')->and($t->fresh()->subscription->quota_used)->toBe(2)
        ->and(Entitlements::for($t)->canSendDocument()->code)->toBe('documents_limit');
});

it('o extrato registra data, envelope, saldo antes e depois; recarregar o mesmo envelope não desconta de novo', function () {
    $t = entTenant(['limits' => ['documents' => 5]]);
    $draft = entDraft($t);
    entSend($draft);
    $e = $draft->fresh();

    $entry = UsageEntry::firstOrFail();
    expect($entry->kind)->toBe('quota')->and($entry->delta)->toBe(-1)->and($entry->balance_after)->toBe(4)
        ->and($entry->envelope_id)->toBe($e->id)->and($entry->occurred_at)->not->toBeNull();

    app(UsageLedger::class)->charge($e, 'send'); // idempotente
    expect(UsageEntry::count())->toBe(1)->and($t->fresh()->subscription->quota_used)->toBe(1);
});

it('a cobrança dentro da transação respeita o limite mesmo quando a checagem prévia passou (corrida)', function () {
    $t = entTenant(['limits' => ['documents' => 1]]);
    $a = entDraft($t);
    $b = entDraft($t);
    expect(Entitlements::for($t)->canSendDocument()->allowed)->toBeTrue(); // os dois passariam na checagem prévia

    app(UsageLedger::class)->charge($a, 'send');
    expect(reasonOf(fn () => app(UsageLedger::class)->charge($b, 'send')))->toBe('documents_limit');
    expect($t->fresh()->subscription->quota_used)->toBe(1)->and(UsageEntry::count())->toBe(1);
});

it('cancelar ou expirar sem assinatura devolve o saldo quando o plano pede; com assinatura não devolve', function () {
    $t = entTenant(['limits' => ['documents' => 3], 'consumption' => ['refund_unsigned' => true]]);
    $a = entDraft($t);
    entSend($a);
    expect($t->fresh()->subscription->quota_used)->toBe(1);

    app(EnvelopeService::class)->cancel($a->fresh());
    expect($t->fresh()->subscription->quota_used)->toBe(0)->and(UsageEntry::where('kind', 'refund')->count())->toBe(1);
    app(UsageLedger::class)->refund($a->fresh()); // não devolve duas vezes
    expect($t->fresh()->subscription->quota_used)->toBe(0)->and(UsageEntry::where('kind', 'refund')->count())->toBe(1);

    $b = entDraft($t);
    entSend($b);
    $b->signers()->first()->update(['status' => 'signed']);
    app(UsageLedger::class)->refund($b->fresh());
    expect($t->fresh()->subscription->quota_used)->toBe(1);

    // sem a regra no plano, cancelar não devolve
    $t2 = entTenant(['limits' => ['documents' => 3]]);
    $c = entDraft($t2);
    entSend($c);
    app(EnvelopeService::class)->cancel($c->fresh());
    expect($t2->fresh()->subscription->quota_used)->toBe(1);
});

it('quando=concluir: o envio não desconta, a conclusão sim, e sem saldo o envio já é barrado', function () {
    $t = entTenant(['limits' => ['documents' => 1], 'consumption' => ['when' => 'complete']]);
    $a = entDraft($t);
    entSend($a);
    expect($t->fresh()->subscription->quota_used)->toBe(0);

    app(UsageLedger::class)->charge($a->fresh(), 'send'); // ainda não é a hora
    expect($t->fresh()->subscription->quota_used)->toBe(0);
    app(UsageLedger::class)->charge($a->fresh(), 'complete');
    expect($t->fresh()->subscription->quota_used)->toBe(1);

    expect(reasonOf(fn () => entSend(entDraft($t))))->toBe('documents_limit');
});

it('créditos que não renovam e cota juntos consomem a cota primeiro e depois os créditos', function () {
    $t = entTenant(['limits' => ['documents' => 1], 'consumption' => ['balance' => 'both']], ['credits_balance' => 2]);

    foreach (range(1, 3) as $i) {
        entSend(entDraft($t));
    }
    $s = $t->fresh()->subscription;
    expect($s->quota_used)->toBe(1)->and($s->credits_balance)->toBe(0)
        ->and(UsageEntry::orderBy('created_at')->pluck('kind')->all())->toEqualCanonicalizing(['quota', 'credit', 'credit']);
    expect(reasonOf(fn () => entSend(entDraft($t))))->toBe('documents_limit');

    $so = entTenant(['consumption' => ['balance' => 'credits']], ['credits_balance' => 1]);
    entSend(entDraft($so));
    expect(reasonOf(fn () => entSend(entDraft($so))))->toBe('documents_limit')->and($so->fresh()->subscription->credits_balance)->toBe(0);
});

it('ao acabar o saldo: cobrar excedente libera e registra; teto limita o excedente', function () {
    $t = entTenant(['limits' => ['documents' => 1], 'consumption' => ['on_empty' => 'overage', 'overage_cents' => 250]]);
    entSend(entDraft($t));
    entSend(entDraft($t));
    expect($t->fresh()->subscription->quota_used)->toBe(2)
        ->and(UsageEntry::where('note', 'like', '%excedente%')->count())->toBe(1);

    $c = entTenant(['limits' => ['documents' => 1], 'consumption' => ['on_empty' => 'cap', 'cap' => 1]]);
    entSend(entDraft($c));
    entSend(entDraft($c)); // 1 de excedente, dentro do teto
    expect(reasonOf(fn () => entSend(entDraft($c))))->toBe('documents_limit');
});

it('a cota renova quando o período vira', function () {
    $t = entTenant(['limits' => ['documents' => 1]]);
    entSend(entDraft($t));
    expect(reasonOf(fn () => entSend(entDraft($t))))->toBe('documents_limit');

    $t->fresh()->subscription->update(['period_start' => now()->subMonths(2), 'period_end' => now()->subDay()]);
    entSend(entDraft($t));
    $s = $t->fresh()->subscription;
    expect($s->quota_used)->toBe(1)->and($s->period_end->isFuture())->toBeTrue();
});

it('assinatura bloqueada ou teste vencido com bloqueio impedem novos envios, mas nunca a assinatura em andamento', function () {
    $t = entTenant([], []);
    $e = entDraft($t);
    $tokens = entSend($e);
    $signer = $e->signers()->first();

    $t->fresh()->subscription->update(['status' => 'blocked']);
    expect(reasonOf(fn () => entSend(entDraft($t))))->toBe('blocked');
    $this->get($signer->fresh()->signUrl())->assertOk(); // quem já recebeu continua assinando

    $teste = entTenant(['trial' => ['days' => 7, 'on_end' => 'block']]);
    expect($teste->subscription->status)->toBe('trialing');
    $this->travel(8)->days();
    expect(reasonOf(fn () => entSend(entDraft($teste))))->toBe('blocked');
});

it('o limite de usuários conta ativos e convites pendentes, não os desativados', function () {
    $t = entTenant(['limits' => ['users' => 2]]);
    $owner = User::create(['tenant_id' => $t->id, 'name' => 'Dono', 'email' => 'dono@x.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $svc = app(TeamService::class);

    [$m] = $svc->invite($owner, 'Um', 'um@x.com'); // 2 de 2 (dono + convite pendente)
    expect(reasonOf(fn () => $svc->invite($owner, 'Dois', 'dois@x.com')))->toBe('users_limit');

    $svc->deactivate($owner, $m); // desativado libera a vaga
    $svc->invite($owner, 'Dois', 'dois@x.com');
    expect(User::where('tenant_id', $t->id)->count())->toBe(3);
});

it('a API responde 403 plan_limit_reached e GET /me mostra plano e consumo', function () {
    $t = entTenant(['name' => 'Plano Pro', 'limits' => ['documents' => 1], 'features' => array_fill_keys(Plan::FEATURES, true)]);
    \App\Models\TenantSigningSetting::updateOrCreate(['tenant_id' => $t->id], ['sender_policy' => 'never']);
    [, $plain] = ApiKey::issue($t, 'k');
    $h = ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
    $payload = fn () => ['title' => 'NDA', 'send' => true, 'document' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF\n"), 'signers' => [['name' => 'Ana', 'email' => 'ana@ex.com']]];

    $this->post('/api/v1/envelopes', $payload(), $h)->assertCreated();
    $this->post('/api/v1/envelopes', $payload(), $h)->assertForbidden()->assertJson(['error' => 'plan_limit_reached', 'reason' => 'documents_limit']);

    $me = $this->getJson('/api/v1/me', $h)->assertOk()->json();
    expect($me['plan']['name'])->toBe('Plano Pro')->and($me['plan']['status'])->toBe('active')
        ->and($me['usage']['documents'])->toMatchArray(['used' => 1, 'limit' => 1]);
});

it('recursos desligados no plano: API, modelos, webhooks e relatórios respondem 403', function () {
    $t = entTenant(['features' => array_fill_keys(Plan::FEATURES, true)]);
    $t->subscription->update(['overrides' => ['features' => ['api' => false, 'templates' => false, 'webhooks' => false, 'reports' => false]]]);
    $user = User::create(['tenant_id' => $t->id, 'name' => 'Dono', 'email' => 'dono@x.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    [, $plain] = ApiKey::issue($t, 'k');

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertForbidden()->assertJson(['error' => 'feature_off']);
    $this->actingAs($user);
    foreach (['/modelos', '/webhooks', '/relatorios'] as $url) {
        $this->get($url)->assertForbidden();
    }
    // o que está ligado continua abrindo
    $this->get('/painel')->assertOk();
});
