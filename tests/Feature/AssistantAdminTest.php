<?php

declare(strict_types=1);

use App\Assistant\AssistantOrchestrator;
use App\Assistant\AssistantPruner;
use App\Assistant\AssistantSettings;
use App\Assistant\Channel\InboundMessage;
use App\Assistant\Llm\LlmClient;
use App\Assistant\Llm\LlmResponse;
use App\Assistant\Metering\MetaUsageLedger;
use App\Assistant\Metering\TokenWallet;
use App\Assistant\Tools\ToolRegistry;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\AssistantTokenEntry;
use App\Models\MetaMessageUsage;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppInbound;
use App\Models\WhatsAppLinkAttempt;
use App\Models\WhatsAppLinkCode;
use App\Plans\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeLlmClient;
use Tests\Support\FakeTool;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-12-10 12:00:00');
    Cache::flush();
    Setting::put('assistant.model', 'luna');
    Setting::put('assistant.model_weights', json_encode(['luna' => 1]));
    Setting::put('assistant.default_package_tokens', '100000');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Empresa com plano com o assistente; devolve o dono. */
function adOwner(bool $assistant = true): User
{
    static $n = 0;
    $n++;
    $plan = Plan::create(['name' => 'AD'.$n, 'slug' => 'ad-'.$n, 'active' => true, 'features' => ['assistant' => $assistant, 'reports' => true]]);
    $tenant = Tenant::create(['name' => 'Empresa AD '.$n]);
    app(PlanService::class)->assign($tenant, $plan);

    return User::create(['tenant_id' => $tenant->id, 'name' => 'Dono '.$n, 'email' => "dono{$n}@ad.test", 'password' => 'senha-muito-forte', 'role' => 'owner']);
}

function adMember(User $owner, string $email = 'membro@ad.test'): User
{
    return User::create(['tenant_id' => $owner->tenant_id, 'name' => 'Membro', 'email' => $email, 'password' => 'senha-muito-forte', 'role' => 'member']);
}

function adSuper(): User
{
    static $n = 0;
    $n++;
    $u = User::create(['tenant_id' => Tenant::create(['name' => 'Plataforma '.$n])->id, 'name' => 'Super', 'email' => "super{$n}@ad.test", 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['platform_role' => 'super_admin'])->save();

    return $u;
}

// ------------------------------------------------------------------ configuração da plataforma

it('só o super administrador abre a configuração da plataforma', function () {
    $this->get('/admin/assistente')->assertRedirect('/entrar');
    $this->actingAs(adOwner())->get('/admin/assistente')->assertNotFound(); // a área da plataforma não revela que existe
    $platformAdmin = adOwner();
    $platformAdmin->forceFill(['platform_role' => 'admin'])->save();
    $this->actingAs($platformAdmin)->get('/admin/assistente')->assertForbidden(); // administrador comum: vê a plataforma, mas não esta área
    $this->actingAs(adSuper())->get('/admin/assistente')->assertOk()->assertSee('Assistente no WhatsApp');
});

it('os segredos nunca aparecem na tela, só "configurado", e o endereço do webhook é mostrado', function () {
    app(AssistantSettings::class)->saveMeta(['access_token' => 'EAAB-segredo-token', 'app_secret' => 'segredo-do-app', 'verify_token' => 'token-verificacao', 'phone_number_id' => 'PNID123']);
    app(AssistantSettings::class)->saveOpenAi(['api_key' => 'sk-segredo-openai']);

    $page = $this->actingAs(adSuper())->get('/admin/assistente');

    $page->assertOk()->assertSee('configurado')->assertSee(route('meta.webhook'), false)->assertSee('PNID123');
    foreach (['EAAB-segredo-token', 'segredo-do-app', 'token-verificacao', 'sk-segredo-openai'] as $secret) {
        $page->assertDontSee($secret, false);
    }
});

it('salva a configuração e mantém o segredo quando o campo vem em branco', function () {
    app(AssistantSettings::class)->saveMeta(['access_token' => 'token-antigo', 'app_secret' => 'segredo-antigo', 'verify_token' => 'verif-antigo', 'phone_number_id' => 'OLD']);
    app(AssistantSettings::class)->saveOpenAi(['api_key' => 'sk-antiga']);

    $this->actingAs(adSuper())->put('/admin/assistente', [
        'access_token' => '', 'app_secret' => '', 'verify_token' => '', 'api_key' => '',
        'phone_number_id' => 'NOVO123', 'display_phone' => '+55 (11) 90000-1111', 'graph_version' => 'v21.0', 'waba_id' => 'WABA9',
        'model' => 'gpt-luna', 'model_weights' => "luna=1\ngrande=3.5", 'markup_percent' => '15', 'default_package_tokens' => '75000', 'overrun_cap' => '5000',
        'output_factor' => '4', 'cached_factor' => '0.1', 'purchase_validity_months' => '12', 'meta_rates' => "utility=0.04\nmarketing=0.35", 'currency' => 'brl',
    ])->assertRedirect('/admin/assistente')->assertSessionHasNoErrors();

    $s = app(AssistantSettings::class);
    expect($s->metaAccessToken())->toBe('token-antigo')->and($s->metaAppSecret())->toBe('segredo-antigo')->and($s->openAiApiKey())->toBe('sk-antiga')
        ->and($s->metaPhoneNumberId())->toBe('NOVO123')->and($s->displayPhone())->toBe('5511900001111')->and($s->model())->toBe('gpt-luna')
        ->and($s->modelWeights())->toBe(['luna' => 1.0, 'grande' => 3.5])->and($s->markupPercent())->toBe(15.0)->and($s->defaultPackageTokens())->toBe(75000)
        ->and($s->overrunCap())->toBe(5000)->and($s->metaRates())->toBe(['utility' => 0.04, 'marketing' => 0.35])->and($s->currency())->toBe('BRL');
});

it('recusa valores inválidos na configuração', function (string $field, string $value) {
    $valid = ['phone_number_id' => 'X', 'model' => 'm', 'model_weights' => 'luna=1', 'markup_percent' => '10', 'default_package_tokens' => '1000', 'overrun_cap' => '100',
        'output_factor' => '4', 'cached_factor' => '0.1', 'purchase_validity_months' => '12', 'meta_rates' => 'utility=0.04', 'currency' => 'BRL'];

    $this->actingAs(adSuper())->put('/admin/assistente', [$field => $value] + $valid)->assertSessionHasErrors($field);
})->with([
    'acréscimo negativo' => ['markup_percent', '-5'],
    'acréscimo absurdo' => ['markup_percent', '5000'],
    'pacote negativo' => ['default_package_tokens', '-1'],
    'excesso não numérico' => ['overrun_cap', 'muito'],
    'peso zero' => ['model_weights', 'luna=0'],
    'peso sem modelo' => ['model_weights', '=2'],
    'peso texto' => ['model_weights', 'luna=alto'],
    'preço negativo' => ['meta_rates', 'utility=-1'],
    'preço sem categoria' => ['meta_rates', '0.5'],
    'moeda inválida' => ['currency', 'REAIS'],
    'validade zero' => ['purchase_validity_months', '0'],
]);

it('o super administrador lança um pacote comprado com validade e nota', function () {
    $owner = adOwner();

    $this->actingAs(adSuper())->post('/admin/assistente/lancamentos', ['tenant_id' => $owner->tenant_id, 'kind' => 'purchase', 'tokens' => '200000', 'note' => 'Pacote extra - pedido 123'])
        ->assertRedirect('/admin/assistente')->assertSessionHasNoErrors();

    $e = AssistantTokenEntry::query()->where('tenant_id', $owner->tenant_id)->first();
    expect($e->kind)->toBe('purchase')->and($e->tokens)->toBe(200000)->and($e->note)->toContain('pedido 123')->and($e->expires_on->toDateString())->toBe('2027-12-10');
});

it('o super administrador ajusta o saldo para mais ou para menos, sempre com nota', function () {
    $owner = adOwner();
    $admin = adSuper();

    $this->actingAs($admin)->post('/admin/assistente/lancamentos', ['tenant_id' => $owner->tenant_id, 'kind' => 'adjust', 'tokens' => '5000', 'note' => 'cortesia'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/admin/assistente/lancamentos', ['tenant_id' => $owner->tenant_id, 'kind' => 'adjust', 'tokens' => '-1000', 'note' => 'correção de erro'])->assertSessionHasNoErrors();

    expect(app(TokenWallet::class)->balance($owner->tenant))->toBe(4000);
    $this->actingAs($admin)->post('/admin/assistente/lancamentos', ['tenant_id' => $owner->tenant_id, 'kind' => 'adjust', 'tokens' => '10', 'note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($admin)->post('/admin/assistente/lancamentos', ['tenant_id' => $owner->tenant_id, 'kind' => 'adjust', 'tokens' => '0', 'note' => 'x'])->assertSessionHasErrors('tokens');
});

it('o super administrador lança o repasse da Meta manualmente, com o acréscimo, e fica registrado', function () {
    Setting::put('assistant.markup_percent', '20');
    $owner = adOwner();

    $this->actingAs(adSuper())->post('/admin/assistente/lancamentos', ['tenant_id' => $owner->tenant_id, 'kind' => 'meta', 'amount' => '12,50', 'note' => 'fatura Meta de novembro'])
        ->assertSessionHasNoErrors();

    $row = MetaMessageUsage::query()->where('tenant_id', $owner->tenant_id)->first();
    expect((float) $row->meta_amount)->toBe(12.5)->and((float) $row->charged_amount)->toBe(15.0)->and($row->category)->toBe('manual')->and($row->wa_message_id)->toStartWith('manual:');
});

it('lançamento para empresa que não existe ou tipo inválido é recusado', function () {
    $admin = adSuper();

    $this->actingAs($admin)->post('/admin/assistente/lancamentos', ['tenant_id' => '00000000-0000-4000-8000-000000000000', 'kind' => 'purchase', 'tokens' => '10', 'note' => 'x'])->assertSessionHasErrors('tenant_id');
    $this->actingAs($admin)->post('/admin/assistente/lancamentos', ['tenant_id' => adOwner()->tenant_id, 'kind' => 'roubo', 'tokens' => '10', 'note' => 'x'])->assertSessionHasErrors('kind');
});

// ------------------------------------------------------------------ painel da empresa

it('só o dono de uma empresa com o recurso abre o painel do assistente', function () {
    $owner = adOwner();
    $member = adMember($owner);
    $without = adOwner(assistant: false);

    $this->get('/assistente')->assertRedirect('/entrar');
    $this->actingAs($owner)->get('/assistente')->assertOk()->assertSee('Assistente no WhatsApp');
    $this->actingAs($member)->get('/assistente')->assertForbidden();
    $this->actingAs($without)->get('/assistente')->assertForbidden();
});

it('o painel mostra o saldo, o uso de tokens do mês e as mensagens da Meta com o valor original e o cobrado', function () {
    Setting::put('assistant.markup_percent', '20');
    $owner = adOwner();
    $w = app(TokenWallet::class);
    $w->ensurePlanPackage($owner->tenant);
    $w->debit($owner->tenant, 'luna', 5000, 0, 0, null);
    app(MetaUsageLedger::class)->record($owner->tenant, 'w1', 'utility', true, 0.50, 'BRL', CarbonImmutable::now());
    app(MetaUsageLedger::class)->record($owner->tenant, 'w2', 'utility', true, 1.50, 'BRL', CarbonImmutable::now());
    app(MetaUsageLedger::class)->record($owner->tenant, 'w3', 'utility', true, 9.00, 'BRL', CarbonImmutable::parse('2026-11-05'));

    $page = $this->actingAs($owner)->get('/assistente');

    $page->assertOk()->assertSee('95.000', false)->assertSee('5.000', false)->assertSee('2,00')->assertSee('2,40')->assertDontSee('9,00');
});

it('o painel da empresa nunca mostra segredos da plataforma nem dados de outra empresa', function () {
    app(AssistantSettings::class)->saveMeta(['access_token' => 'EAAB-segredo', 'app_secret' => 'seg-app', 'verify_token' => 'seg-verif', 'phone_number_id' => 'P1']);
    app(AssistantSettings::class)->saveOpenAi(['api_key' => 'sk-segredo']);
    $owner = adOwner();
    $other = adOwner();
    app(MetaUsageLedger::class)->record($other->tenant, 'wx', 'utility', true, 77.77, 'BRL', CarbonImmutable::now());

    $page = $this->actingAs($owner)->get('/assistente');

    foreach (['EAAB-segredo', 'seg-app', 'seg-verif', 'sk-segredo', '77,77'] as $secret) {
        $page->assertDontSee($secret, false);
    }
});

it('o dono liga e desliga o assistente da empresa; desligado, a IA nem é chamada', function () {
    $owner = adOwner();
    $llm = new FakeLlmClient([LlmResponse::text('não deveria')]);
    app()->instance(LlmClient::class, $llm);
    app()->instance(ToolRegistry::class, new ToolRegistry([]));

    $this->actingAs($owner)->put('/assistente', ['enabled' => '0', 'groups' => ['consult', 'send_pdf', 'contracts']])->assertRedirect('/assistente');
    $reply = app(AssistantOrchestrator::class)->handle($owner->fresh(), new InboundMessage('wamid.A', '5511999990000', 'text', 'oi'));

    expect($owner->tenant->fresh()->assistant_enabled)->toBeFalse()->and($llm->calls)->toBe([])->and($reply->text)->toContain('desativado');
});

it('o dono escolhe quais usuários podem usar; quem foi desligado recebe aviso e a IA não é chamada', function () {
    $owner = adOwner();
    $member = adMember($owner);
    $llm = new FakeLlmClient([LlmResponse::text('ok')]);
    app()->instance(LlmClient::class, $llm);
    app()->instance(ToolRegistry::class, new ToolRegistry([]));

    $this->actingAs($owner)->put('/assistente', ['enabled' => '1', 'groups' => ['consult'], 'users' => [$owner->id]])->assertSessionHasNoErrors();

    expect($member->fresh()->assistant_enabled)->toBeFalse()->and($owner->fresh()->assistant_enabled)->toBeTrue();
    $reply = app(AssistantOrchestrator::class)->handle($member->fresh(), new InboundMessage('wamid.B', '5511999990001', 'text', 'oi'));
    expect($llm->calls)->toBe([])->and($reply->text)->toContain('desativado');
});

it('o dono não consegue ligar ou desligar usuário de outra empresa', function () {
    $owner = adOwner();
    $other = adOwner();
    $strangerMember = adMember($other, 'estranho@ad.test');

    $this->actingAs($owner)->put('/assistente', ['enabled' => '1', 'groups' => ['consult'], 'users' => [$strangerMember->id]]);

    expect($strangerMember->fresh()->assistant_enabled)->toBeTrue(); // não foi tocado
});

it('as ações permitidas limitam as ferramentas que a IA recebe e o que o menu oferece', function () {
    $owner = adOwner();
    $send = new FakeTool('send_signed_pdf');
    $list = new FakeTool('list_documents', ['type' => 'object', 'properties' => ['status' => ['type' => 'string']]]);
    $llm = new FakeLlmClient([LlmResponse::tools([['id' => 'c1', 'name' => 'send_signed_pdf', 'arguments' => []]]), LlmResponse::text('ok')]);
    app()->instance(LlmClient::class, $llm);
    app()->instance(ToolRegistry::class, new ToolRegistry([$send, $list]));
    $this->actingAs($owner)->put('/assistente', ['enabled' => '1', 'groups' => ['consult'], 'users' => [$owner->id]])->assertSessionHasNoErrors();

    app(AssistantOrchestrator::class)->handle($owner->fresh(), new InboundMessage('wamid.C', '5511999990002', 'text', 'mande o pdf'));

    expect(array_column(array_column($llm->calls[0]['tools'], 'function'), 'name'))->toBe(['list_documents'])->and($send->runs)->toBe([]);
});

it('o botão do menu de uma ação desligada não executa a consulta', function () {
    $owner = adOwner();
    $contracts = new FakeTool('list_contracts_due');
    app()->instance(ToolRegistry::class, new ToolRegistry([$contracts]));
    app()->instance(LlmClient::class, new FakeLlmClient([]));
    $this->actingAs($owner)->put('/assistente', ['enabled' => '1', 'groups' => ['consult'], 'users' => [$owner->id]]);

    $reply = app(AssistantOrchestrator::class)->handle($owner->fresh(), new InboundMessage('wamid.D', '5511999990003', 'button', 'Contratos', payloadId: 'menu_contratos'));

    expect($contracts->runs)->toBe([])->and($reply->text)->toContain('indispon');
});

it('grupos de ações desconhecidos são recusados', function () {
    $owner = adOwner();

    $this->actingAs($owner)->put('/assistente', ['enabled' => '1', 'groups' => ['apagar_tudo']])->assertSessionHasErrors('groups.0');
});

it('o menu mostra "Assistente" só para o dono de uma empresa com o recurso', function () {
    $owner = adOwner();
    $member = adMember($owner);
    $without = adOwner(assistant: false);

    $labels = fn (User $u) => collect(app(\App\Themes\MenuBuilder::class)->for($u))->pluck('label')->all();

    expect($labels($owner))->toContain('Assistente')->and($labels($member))->not->toContain('Assistente')->and($labels($without))->not->toContain('Assistente');
});

// ------------------------------------------------------------------ limpeza (90 dias)

it('a limpeza apaga conversas, mensagens recebidas e códigos antigos e mantém o que é recente', function () {
    $owner = adOwner();
    $old = AssistantConversation::query()->create(['tenant_id' => $owner->tenant_id, 'user_id' => $owner->id, 'last_message_at' => now()->subDays(91)]);
    AssistantMessage::query()->create(['conversation_id' => $old->id, 'role' => 'user', 'content' => 'antiga']);
    $new = AssistantConversation::query()->create(['tenant_id' => $owner->tenant_id, 'user_id' => $owner->id, 'last_message_at' => now()->subDays(10)]);
    AssistantMessage::query()->create(['conversation_id' => $new->id, 'role' => 'user', 'content' => 'recente']);
    WhatsAppInbound::query()->create(['wa_message_id' => 'w-old', 'from_phone' => '551100', 'type' => 'text', 'payload' => [], 'received_at' => now()->subDays(100), 'created_at' => now()->subDays(100)]);
    WhatsAppInbound::query()->create(['wa_message_id' => 'w-new', 'from_phone' => '551100', 'type' => 'text', 'payload' => [], 'received_at' => now()->subDays(1), 'created_at' => now()->subDays(1)]);
    WhatsAppLinkCode::query()->create(['user_id' => $owner->id, 'code_hash' => str_repeat('a', 64), 'expires_at' => now()->subDays(3)]);
    WhatsAppLinkAttempt::query()->create(['phone' => '5511', 'attempts' => 2, 'updated_at' => now()->subDays(30)]);

    $counts = app(AssistantPruner::class)->run();

    expect(AssistantConversation::query()->pluck('id')->all())->toBe([$new->id])->and(AssistantMessage::query()->pluck('content')->all())->toBe(['recente'])
        ->and(WhatsAppInbound::query()->pluck('wa_message_id')->all())->toBe(['w-new'])->and(WhatsAppLinkCode::query()->count())->toBe(0)->and(WhatsAppLinkAttempt::query()->count())->toBe(0)
        ->and($counts['conversations'])->toBe(1);
});

it('a limpeza não apaga os lançamentos de tokens nem o uso da Meta (são registro financeiro)', function () {
    $owner = adOwner();
    app(TokenWallet::class)->adjust($owner->tenant, 100, 'x');
    AssistantTokenEntry::query()->update(['created_at' => now()->subYears(2)]);
    app(MetaUsageLedger::class)->record($owner->tenant, 'w-fin', 'utility', true, 1, 'BRL', now()->subYears(2));

    app(AssistantPruner::class)->run();

    expect(AssistantTokenEntry::query()->count())->toBe(1)->and(MetaMessageUsage::query()->count())->toBe(1);
});

it('o comando assistant:prune existe e roda todo dia', function () {
    $this->artisan('assistant:prune')->assertExitCode(0);

    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
    expect($events)->toContain('assistant:prune');
});
