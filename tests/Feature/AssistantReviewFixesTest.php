<?php

declare(strict_types=1);

use App\Assistant\AssistantOrchestrator;
use App\Assistant\AssistantPruner;
use App\Assistant\AssistantSettings;
use App\Assistant\Channel\AssistantInboundHandler;
use App\Assistant\Channel\InboundHandler;
use App\Assistant\Channel\InboundMessage;
use App\Assistant\Channel\StatusReconciler;
use App\Assistant\Linking\WhatsAppLinker;
use App\Assistant\Llm\LlmClient;
use App\Assistant\Llm\LlmResponse;
use App\Assistant\Metering\TokenWallet;
use App\Assistant\Tools\SendSignedPdfTool;
use App\Assistant\Tools\ToolRegistry;
use App\Assistant\Tools\ToolResult;
use App\Jobs\ProcessInboundWhatsApp;
use App\Models\AssistantTokenEntry;
use App\Models\Envelope;
use App\Models\MetaMessageUsage;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppInbound;
use App\Models\WhatsAppLinkAttempt;
use App\Models\WhatsAppStatusEvent;
use App\Plans\PlanService;
use App\Team\TeamService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeLlmClient;
use Tests\Support\FakeTool;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-12-10 12:00:00');
    Cache::flush();
    Storage::fake('local');
    Setting::put('assistant.model', 'luna');
    Setting::put('assistant.model_weights', json_encode(['luna' => 1]));
    Setting::put('assistant.default_package_tokens', '100000');
    Setting::put('assistant.overrun_cap', '20000');
    Setting::put('assistant.output_factor', '4');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function rvUser(array $features = ['assistant' => true, 'reports' => true], string $phone = '5511999990000'): User
{
    static $n = 0;
    $n++;
    $plan = Plan::create(['name' => 'RV'.$n, 'slug' => 'rv-'.$n, 'active' => true, 'features' => $features]);
    $tenant = Tenant::create(['name' => 'Empresa RV '.$n]);
    app(PlanService::class)->assign($tenant, $plan);
    $u = User::create(['tenant_id' => $tenant->id, 'name' => 'Ana Souza', 'email' => "ana{$n}@rv.test", 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['whatsapp_phone' => $phone === '5511999990000' ? '551199'.str_pad((string) $n, 7, '0', STR_PAD_LEFT) : $phone, 'whatsapp_verified_at' => now()])->save();

    return $u->fresh();
}

function rvMsg(User $u, string $text = 'oi', string $type = 'text', ?string $payload = null): InboundMessage
{
    static $k = 0;
    $k++;

    return new InboundMessage("wamid.RV{$k}", (string) $u->whatsapp_phone, $type, $text, payloadId: $payload, timestamp: time());
}

// ------------------------------------------------------------------ 1. usuário desativado, convite pendente e empresa suspensa

it('usuário desativado ou com convite pendente não é reconhecido pelo número', function () {
    $linker = app(WhatsAppLinker::class);
    $a = rvUser();
    $b = rvUser();

    expect($linker->userFor($a->whatsapp_phone)?->id)->toBe($a->id);
    $a->forceFill(['deactivated_at' => now()])->save();
    $b->forceFill(['invite_token_hash' => str_repeat('a', 64)])->save();

    expect($linker->userFor($a->whatsapp_phone))->toBeNull()->and($linker->userFor($b->whatsapp_phone))->toBeNull();
});

it('desativar o usuário na equipe solta o número do WhatsApp dele', function () {
    $owner = rvUser();
    $member = User::create(['tenant_id' => $owner->tenant_id, 'name' => 'Membro', 'email' => 'membro@rv.test', 'password' => 'senha-muito-forte', 'role' => 'member']);
    $member->forceFill(['whatsapp_phone' => '5511988880001', 'whatsapp_verified_at' => now()])->save();

    app(TeamService::class)->deactivate($owner, $member->fresh());

    expect($member->fresh()->whatsapp_phone)->toBeNull()->and($member->fresh()->whatsapp_verified_at)->toBeNull();
});

it('usuário desativado não vincula número com um código que ainda valia', function () {
    $u = rvUser();
    $u->forceFill(['whatsapp_phone' => null, 'whatsapp_verified_at' => null])->save();
    $linker = app(WhatsAppLinker::class);
    $code = $linker->start($u->fresh());
    $u->forceFill(['deactivated_at' => now()])->save();

    expect($linker->claim('5511977770000', $code))->toBeNull();
});

it('empresa suspensa não é atendida: mensagem fixa e a IA não é chamada', function () {
    $u = rvUser();
    $u->tenant->forceFill(['suspended_at' => now()])->save();
    $llm = new FakeLlmClient([LlmResponse::text('não deveria')]);
    app()->instance(LlmClient::class, $llm);
    app()->instance(ToolRegistry::class, new ToolRegistry([]));

    $reply = app(AssistantOrchestrator::class)->handle($u->fresh(), rvMsg($u));

    expect($llm->calls)->toBe([])->and($reply->text)->toContain('suspensa');
});

// ------------------------------------------------------------------ 2. processamento exatamente uma vez

function rvInbound(User $u): WhatsAppInbound
{
    return WhatsAppInbound::query()->create(['wa_message_id' => 'wamid.J'.uniqid(), 'from_phone' => $u->whatsapp_phone, 'type' => 'text', 'payload' => ['text' => 'oi'], 'received_at' => now()]);
}

it('o mesmo recebimento só é tratado uma vez, mesmo com dois workers ou reentrega do job', function () {
    $u = rvUser();
    $row = rvInbound($u);
    $calls = [];
    $handler = new class($calls) implements InboundHandler {
        public function __construct(public array &$calls) {}
        public function handle(InboundMessage $message): void { $this->calls[] = $message->waMessageId; }
    };

    (new ProcessInboundWhatsApp($row->id))->handle($handler);
    (new ProcessInboundWhatsApp($row->id))->handle($handler);

    expect($calls)->toHaveCount(1)->and($row->fresh()->processing_started_at)->not->toBeNull()->and($row->fresh()->processed_at)->not->toBeNull();
});

it('se o tratador falha, a fila não repete o job (repetir cobraria a IA e mandaria as respostas de novo)', function () {
    $u = rvUser();
    $row = rvInbound($u);
    $handler = new class implements InboundHandler {
        public int $n = 0;
        public function handle(InboundMessage $message): void { $this->n++; throw new RuntimeException('caiu'); }
    };

    expect(fn () => (new ProcessInboundWhatsApp($row->id))->handle($handler))->toThrow(RuntimeException::class);
    (new ProcessInboundWhatsApp($row->id))->handle($handler); // reentrega: ignora

    expect($handler->n)->toBe(1)->and((new ProcessInboundWhatsApp($row->id))->tries)->toBe(1);
});

it('o tempo limite do job é menor que o retry_after da fila, e o job não roda em paralelo para o mesmo número', function () {
    $u = rvUser();
    $row = rvInbound($u);
    $job = new ProcessInboundWhatsApp($row->id);

    expect($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))->and($job->failOnTimeout)->toBeTrue();
    $mw = $job->middleware();
    expect($mw)->toHaveCount(1)->and($mw[0])->toBeInstanceOf(WithoutOverlapping::class)->and($mw[0]->key)->toContain((string) $u->whatsapp_phone);
});

it('a varredura reenvia para a fila só mensagens que nunca começaram (despacho perdido)', function () {
    Queue::fake();
    $u = rvUser();
    $lost = rvInbound($u);
    $lost->forceFill(['created_at' => now()->subMinutes(10)])->save();
    $recent = rvInbound($u);
    $started = rvInbound($u);
    $started->forceFill(['created_at' => now()->subMinutes(10), 'processing_started_at' => now()->subMinutes(9)])->save();
    $done = rvInbound($u);
    $done->forceFill(['created_at' => now()->subMinutes(10), 'processed_at' => now()->subMinutes(9), 'processing_started_at' => now()->subMinutes(9)])->save();

    $this->artisan('assistant:redispatch')->assertExitCode(0);

    Queue::assertPushed(ProcessInboundWhatsApp::class, 1);
    Queue::assertPushed(ProcessInboundWhatsApp::class, fn ($j) => $j->inboundId === $lost->id);
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
    expect($events)->toContain('assistant:redispatch');
});

it('o laço da IA tem um prazo total: passou do tempo, para e avisa', function () {
    config(['assistant.max_seconds' => -1]);
    $u = rvUser();
    $tool = new FakeTool();
    $llm = new FakeLlmClient([LlmResponse::tools([['id' => 'c1', 'name' => 'consultar', 'arguments' => []]]), LlmResponse::text('não deveria chegar')]);
    app()->instance(LlmClient::class, $llm);
    app()->instance(ToolRegistry::class, new ToolRegistry([$tool]));

    $reply = app(AssistantOrchestrator::class)->handle($u, rvMsg($u, 'x'));

    expect(count($llm->calls))->toBe(1)->and($reply->text)->toContain('Não consegui concluir');
});

// ------------------------------------------------------------------ 3. excesso e ajuste negativo cobrados uma vez só

it('o excesso de um mês é quitado no próximo pacote, uma vez, e não vira desconto eterno', function () {
    $u = rvUser();
    $t = $u->tenant;
    $w = app(TokenWallet::class);
    Setting::put('assistant.default_package_tokens', '50000');
    $w->ensurePlanPackage($t);
    $w->debit($t, 'luna', 60000, 0, 0, null); // usa o pacote (50000) e 10000 de excesso
    expect($w->balance($t))->toBe(-10000);

    CarbonImmutable::setTestNow('2027-01-05 12:00:00');
    $w->ensurePlanPackage($t);
    expect($w->balance($t))->toBe(40000); // 50000 - 10000 quitados

    CarbonImmutable::setTestNow('2027-02-05 12:00:00');
    $w->ensurePlanPackage($t);
    expect($w->balance($t))->toBe(50000); // sem desconto de novo
});

it('o ajuste negativo desconta uma vez, do saldo que existe, e não de novo nos meses seguintes', function () {
    $u = rvUser();
    $t = $u->tenant;
    $w = app(TokenWallet::class);
    Setting::put('assistant.default_package_tokens', '50000');
    $w->ensurePlanPackage($t);

    $w->adjust($t, -1000, 'correção');
    expect($w->balance($t))->toBe(49000);

    CarbonImmutable::setTestNow('2027-01-05 12:00:00');
    $w->ensurePlanPackage($t);
    expect($w->balance($t))->toBe(50000);
});

it('ajuste negativo maior que o saldo deixa dívida que o próximo crédito quita', function () {
    $u = rvUser();
    $t = $u->tenant;
    $w = app(TokenWallet::class);
    $w->adjust($t, -300, 'estorno');
    expect($w->balance($t))->toBe(-300);

    $w->adjust($t, 1000, 'cortesia');
    expect($w->balance($t))->toBe(700);
});

// ------------------------------------------------------------------ 4. preço das mensagens

it('aviso de um número que ninguém tinha vinculado nunca é cobrado de quem vincula depois', function () {
    WhatsAppStatusEvent::query()->create(['wa_message_id' => 'wamid.OLD', 'recipient_phone' => '5511977771111', 'category' => 'utility', 'billable' => true, 'occurred_at' => now()]);
    Setting::put('assistant.meta_rates', json_encode(['utility' => 0.04]));
    $u = rvUser(phone: '5511977771111');

    $n = app(StatusReconciler::class)->run();

    expect($n)->toBe(0)->and(MetaMessageUsage::query()->count())->toBe(0);
});

it('aviso de empresa conhecida, mas sem preço cadastrado na hora, é cobrado quando o preço aparece', function () {
    $u = rvUser();
    WhatsAppStatusEvent::query()->create(['wa_message_id' => 'wamid.P1', 'tenant_id' => $u->tenant_id, 'recipient_phone' => '5511977772222', 'category' => 'utility', 'billable' => true, 'occurred_at' => now()]);
    expect(app(StatusReconciler::class)->run())->toBe(0);

    Setting::put('assistant.meta_rates', json_encode(['utility' => 0.04]));

    expect(app(StatusReconciler::class)->run())->toBe(1)->and(MetaMessageUsage::query()->where('tenant_id', $u->tenant_id)->count())->toBe(1);
});

it('salvar os preços na tela da plataforma concilia os avisos que esperavam', function () {
    $u = rvUser();
    WhatsAppStatusEvent::query()->create(['wa_message_id' => 'wamid.P2', 'tenant_id' => $u->tenant_id, 'recipient_phone' => '5511977773333', 'category' => 'utility', 'billable' => true, 'occurred_at' => now()]);
    $admin = User::create(['tenant_id' => Tenant::create(['name' => 'Plataforma'])->id, 'name' => 'Super', 'email' => 'super@rv.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $admin->forceFill(['platform_role' => 'super_admin'])->save();

    $this->actingAs($admin)->put('/admin/assistente', ['phone_number_id' => 'X', 'model' => 'm', 'model_weights' => 'luna=1', 'markup_percent' => '10', 'default_package_tokens' => '1000',
        'overrun_cap' => '100', 'output_factor' => '4', 'cached_factor' => '0.1', 'purchase_validity_months' => '12', 'meta_rates' => 'utility=0.04', 'currency' => 'BRL'])->assertSessionHasNoErrors();

    expect(MetaMessageUsage::query()->where('tenant_id', $u->tenant_id)->count())->toBe(1);
});

it('a conciliação é agendada e a limpeza descarta avisos sem empresa depois de 30 dias', function () {
    WhatsAppStatusEvent::query()->create(['wa_message_id' => 'wamid.S1', 'recipient_phone' => '5511900000001', 'category' => 'utility', 'billable' => true, 'occurred_at' => now()->subDays(40), 'created_at' => now()->subDays(40)]);
    WhatsAppStatusEvent::query()->create(['wa_message_id' => 'wamid.S2', 'recipient_phone' => '5511900000002', 'category' => 'utility', 'billable' => true, 'occurred_at' => now()->subDays(5), 'created_at' => now()->subDays(5)]);

    app(AssistantPruner::class)->run();

    expect(WhatsAppStatusEvent::query()->pluck('wa_message_id')->all())->toBe(['wamid.S2']);
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
    expect($events)->toContain('assistant:reconcile');
});

// ------------------------------------------------------------------ 6. escolha na lista interativa

it('escolher um documento numa lista (id doc_<uuid>) funciona, e o título cortado também acha', function () {
    $u = rvUser();
    $e = wzDraft($u, 'Contrato de Prestação de Serviços de Manutenção Predial');
    $e->forceFill(['status' => 'sent'])->save();

    $tool = app(\App\Assistant\Tools\DocumentStatusTool::class);

    expect($tool->run($u, ['document' => 'doc_'.$e->id])->text)->toContain('Contrato de Prestação');
});

// ------------------------------------------------------------------ 7. uso no piso do excesso continua medido

it('no piso do excesso o uso real continua registrado (com 0 cobrado) e o laço para', function () {
    $u = rvUser();
    $w = app(TokenWallet::class);
    Setting::put('assistant.default_package_tokens', '100');
    Setting::put('assistant.overrun_cap', '50');
    $w->ensurePlanPackage($u->tenant);
    $w->debit($u->tenant, 'luna', 150, 0, 0, null); // -> -50 (piso)

    $charged = $w->debit($u->tenant, 'luna', 700, 0, 0, 'c1');

    $row = AssistantTokenEntry::query()->where('conversation_id', 'c1')->first();
    expect($charged)->toBe(0)->and($row)->not->toBeNull()->and($row->tokens)->toBe(0)->and($row->input_tokens)->toBe(700);
});

it('quando o saldo chega ao piso no meio da conversa, a IA não é chamada de novo', function () {
    Setting::put('assistant.default_package_tokens', '100');
    Setting::put('assistant.overrun_cap', '0');
    $u = rvUser();
    $tool = new FakeTool();
    $llm = new FakeLlmClient([
        LlmResponse::tools([['id' => 'c1', 'name' => 'consultar', 'arguments' => []]], ['input' => 500]),
        LlmResponse::tools([['id' => 'c2', 'name' => 'consultar', 'arguments' => []]], ['input' => 500]),
        LlmResponse::text('não deveria chegar'),
    ]);
    app()->instance(LlmClient::class, $llm);
    app()->instance(ToolRegistry::class, new ToolRegistry([$tool]));

    $reply = app(AssistantOrchestrator::class)->handle($u, rvMsg($u, 'x'));

    expect(count($llm->calls))->toBe(2)->and($reply->text)->toContain('tokens');
});

// ------------------------------------------------------------------ 8. código de vínculo e tentativas

it('palavras comuns de 8 letras não contam como tentativa de código', function () {
    $linker = app(WhatsAppLinker::class);
    foreach (['meu whatsapp', 'documento pendente?', 'PENDENTE', 'WHATSAPP', 'CONTRATOS', 'Assinados'] as $text) {
        $linker->claim('5511966660000', $text);
    }

    expect(WhatsAppLinkAttempt::query()->find('5511966660000'))->toBeNull()->and($linker->isBlocked('5511966660000'))->toBeFalse();
});

it('todo código gerado tem letras e números', function () {
    $u = rvUser();
    $linker = app(WhatsAppLinker::class);
    for ($i = 0; $i < 60; $i++) {
        $code = $linker->start($u);
        expect($code)->toMatch('/[A-Z]/')->toMatch('/[2-9]/');
    }
});

it('tentativas erradas em sequência acumulam sem erro mesmo quando a linha já existe', function () {
    $linker = app(WhatsAppLinker::class);
    foreach (['AAAA2222', 'BBBB3333', 'CCCC4444'] as $wrong) {
        $linker->claim('5511955550000', $wrong);
    }

    expect((int) WhatsAppLinkAttempt::query()->find('5511955550000')->attempts)->toBe(3);
});

// ------------------------------------------------------------------ 9. resposta com lista

it('texto longo com lista: o texto vai inteiro antes e a lista depois', function () {
    $u = rvUser();
    app(AssistantSettings::class)->saveMeta(['access_token' => 't', 'phone_number_id' => 'PNID123', 'app_secret' => 's', 'verify_token' => 'v']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    $list = ['body' => 'Seus documentos', 'button' => 'Ver', 'rows' => [['id' => 'doc_1', 'title' => 'Contrato A']]];
    $tool = new FakeTool(result: fn () => new ToolResult('1 documento', list: $list));
    $long = str_repeat('Resposta longa. ', 120);
    app()->instance(LlmClient::class, new FakeLlmClient([LlmResponse::tools([['id' => 'c1', 'name' => 'consultar', 'arguments' => []]]), LlmResponse::text($long)]));
    app()->instance(ToolRegistry::class, new ToolRegistry([$tool]));

    app(AssistantInboundHandler::class)->handle(rvMsg($u, 'liste'));

    Http::assertSent(fn (Request $r) => ($r['type'] ?? null) === 'text' && mb_strlen($r['text']['body']) >= 1500);
    Http::assertSent(fn (Request $r) => ($r['type'] ?? null) === 'interactive' && $r['interactive']['type'] === 'list');
});

it('a lista só acompanha a resposta quando vem da última consulta', function () {
    $u = rvUser();
    $withList = new FakeTool('listar', ['type' => 'object', 'properties' => []], result: fn () => new ToolResult('1 doc', list: ['body' => 'Docs', 'button' => 'Ver', 'rows' => [['id' => 'doc_1', 'title' => 'A']]]));
    $plain = new FakeTool('outra', ['type' => 'object', 'properties' => []], result: fn () => new ToolResult('verificado'));
    app()->instance(LlmClient::class, new FakeLlmClient([
        LlmResponse::tools([['id' => 'c1', 'name' => 'listar', 'arguments' => []]]),
        LlmResponse::tools([['id' => 'c2', 'name' => 'outra', 'arguments' => []]]),
        LlmResponse::text('Está verificado.'),
    ]));
    app()->instance(ToolRegistry::class, new ToolRegistry([$withList, $plain]));

    $reply = app(AssistantOrchestrator::class)->handle($u, rvMsg($u, 'x'));

    expect($reply->list)->toBeNull();
});

// ------------------------------------------------------------------ 10. envio do PDF

function rvDone(User $u, string $title = 'Contrato Alfa', int $files = 1): Envelope
{
    $e = wzDraft($u, $title);
    for ($i = 2; $i <= $files; $i++) {
        app(\App\Envelopes\EnvelopeService::class)->addDocument($e->fresh(), "%PDF-1.4\n% extra {$i}\n%%EOF\n", "anexo{$i}.pdf");
    }
    $path = "tenants/{$u->tenant_id}/final/{$e->id}.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 selado grande');
    $e->forceFill(['status' => 'completed', 'completed_at' => now(), 'final_path' => $path])->save(); // final_size fica nulo de propósito
    foreach ($e->extraDocuments()->get() as $x) {
        $p = "tenants/{$u->tenant_id}/final/{$e->id}-{$x->position}.pdf";
        Storage::disk('local')->put($p, '%PDF-1.4 anexo');
        $x->update(['final_path' => $p]);
    }

    return $e->fresh();
}

it('arquivo sem tamanho gravado também respeita o limite de envio', function () {
    Http::fake();
    config(['assistant.max_send_mb' => 0.00001]);
    $u = rvUser();
    $e = rvDone($u, 'Contrato Alfa');

    $r = app(SendSignedPdfTool::class)->run($u, ['document' => 'alfa']);

    expect($r->text)->toContain('grande demais')->toContain(route('envelopes.show', $e));
    Http::assertNothingSent();
});

it('se o segundo arquivo falha, a resposta diz o que já foi enviado', function () {
    app(AssistantSettings::class)->saveMeta(['access_token' => 't', 'phone_number_id' => 'PNID123', 'app_secret' => 's', 'verify_token' => 'v']);
    $calls = 0;
    Http::fake(function (Request $r) use (&$calls) {
        if (str_ends_with($r->url(), '/media')) {
            $calls++;

            return $calls === 1 ? Http::response(['id' => 'MID1'], 200) : Http::response(['error' => ['message' => 'x', 'code' => 131047]], 400);
        }

        return Http::response(['messages' => [['id' => 'wamid.DOC']]], 200);
    });
    $u = rvUser();
    rvDone($u, 'Pacote Delta', 2);

    $r = app(SendSignedPdfTool::class)->run($u, ['document' => 'delta']);

    expect($r->text)->toContain('Não consegui enviar')->toContain('1 de 2');
});
