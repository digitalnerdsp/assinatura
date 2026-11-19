<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Channel\InboundHandler;
use App\Assistant\Channel\InboundMessage;
use App\Assistant\Linking\LinkingInboundHandler;
use App\Assistant\Linking\WhatsAppLinker;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppLinkCode;
use App\Plans\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-12-10 12:00:00');
    Cache::flush();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function wlUser(string $email = 'ana@wl.test', bool $assistant = true): User
{
    static $n = 0;
    $n++;
    $plan = Plan::create(['name' => 'WL'.$n, 'slug' => 'wl-'.$n, 'active' => true, 'features' => ['assistant' => $assistant]]);
    $tenant = Tenant::create(['name' => 'Empresa WL '.$n]);
    app(PlanService::class)->assign($tenant, $plan);

    return User::create(['tenant_id' => $tenant->id, 'name' => 'Ana Souza', 'email' => $email, 'password' => 'senha-muito-forte', 'role' => 'owner']);
}

function wlMsg(string $from, string $text, ?string $id = null): InboundMessage
{
    static $k = 0;
    $k++;

    return new InboundMessage($id ?? "wamid.L{$k}", $from, 'text', $text, timestamp: time());
}

const WL_ALPHABET = '/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/';

// ------------------------------------------------------------------ código

it('gera um código legível de 8 caracteres, guardado só como hash, válido por 15 minutos', function () {
    $u = wlUser();

    $code = app(WhatsAppLinker::class)->start($u);

    expect($code)->toMatch(WL_ALPHABET);
    $row = WhatsAppLinkCode::query()->where('user_id', $u->id)->first();
    expect($row->code_hash)->not->toContain($code)->and($row->expires_at->toIso8601String())->toBe(CarbonImmutable::now()->addMinutes(15)->toIso8601String());
    expect(json_encode(WhatsAppLinkCode::query()->get()->toArray()))->not->toContain($code);
});

it('um código novo invalida o anterior', function () {
    $u = wlUser();
    $linker = app(WhatsAppLinker::class);
    $old = $linker->start($u);
    $new = $linker->start($u);

    expect($linker->claim('5511911110000', $old))->toBeNull();
    expect($linker->claim('5511911110000', $new)?->id)->toBe($u->id);
});

it('mensagem com o código de um número novo vincula o número ao usuário', function () {
    $u = wlUser();
    $linker = app(WhatsAppLinker::class);
    $code = $linker->start($u);

    $claimed = $linker->claim('5511999990000', $code);

    expect($claimed?->id)->toBe($u->id)->and($u->fresh()->whatsapp_phone)->toBe('5511999990000')->and($u->fresh()->whatsapp_verified_at)->not->toBeNull()
        ->and($linker->userFor('5511999990000')?->id)->toBe($u->id);
});

it('aceita o código com hífen, espaços, minúsculas ou dentro de uma frase', function (string $format) {
    $u = wlUser();
    $linker = app(WhatsAppLinker::class);
    $code = $linker->start($u);
    $text = str_replace('{c}', $code, $format);
    $text = str_replace(['{c4a}', '{c4b}'], [substr($code, 0, 4), substr($code, 4)], $text);

    expect($linker->claim('5511999990000', $text)?->id)->toBe($u->id);
})->with(['{c4a}-{c4b}', '{c4a} {c4b}', 'meu código é {c}', '  {c}  ']);

it('o código serve uma vez só', function () {
    $u = wlUser();
    $linker = app(WhatsAppLinker::class);
    $code = $linker->start($u);

    $linker->claim('5511999990000', $code);

    expect($linker->claim('5511888880000', $code))->toBeNull();
});

it('código expirado ou errado não vincula', function () {
    $u = wlUser();
    $linker = app(WhatsAppLinker::class);
    $code = $linker->start($u);

    expect($linker->claim('5511999990000', 'AAAA2222'))->toBeNull();
    CarbonImmutable::setTestNow('2026-12-10 12:16:00');
    expect($linker->claim('5511999990000', $code))->toBeNull()->and($u->fresh()->whatsapp_phone)->toBeNull();
});

it('conversa comum não conta como tentativa de código', function () {
    $linker = app(WhatsAppLinker::class);
    foreach (['Oi', 'bom dia', 'Quais documentos estão pendentes?', 'ok'] as $text) {
        $linker->claim('5511999990000', $text);
    }
    $u = wlUser();
    $code = $linker->start($u);

    expect($linker->claim('5511999990000', $code)?->id)->toBe($u->id);
});

// ------------------------------------------------------------------ bloqueio

it('5 tentativas erradas do mesmo número bloqueiam esse número por 1 hora', function () {
    $u = wlUser();
    $linker = app(WhatsAppLinker::class);
    $code = $linker->start($u);

    foreach (['AAAA2222', 'BBBB3333', 'CCCC4444', 'DDDD5555', 'EEEE6666'] as $wrong) {
        $linker->claim('5511999990000', $wrong);
    }

    expect($linker->isBlocked('5511999990000'))->toBeTrue();
    expect($linker->claim('5511999990000', $code))->toBeNull(); // nem o certo vale durante o bloqueio
    expect($linker->isBlocked('5511777770000'))->toBeFalse();
    expect($linker->claim('5511777770000', $code)?->id)->toBe($u->id); // outro número não é afetado

    CarbonImmutable::setTestNow('2026-12-10 13:01:00');
    expect($linker->isBlocked('5511999990000'))->toBeFalse();
});

it('quatro erros e um acerto não bloqueiam e zeram a contagem', function () {
    $u = wlUser();
    $linker = app(WhatsAppLinker::class);
    $code = $linker->start($u);
    foreach (['AAAA2222', 'BBBB3333', 'CCCC4444', 'DDDD5555'] as $wrong) {
        $linker->claim('5511999990000', $wrong);
    }

    expect($linker->claim('5511999990000', $code)?->id)->toBe($u->id)->and($linker->isBlocked('5511999990000'))->toBeFalse();
});

// ------------------------------------------------------------------ posse e troca de número

it('número já vinculado a outro usuário não pode ser reclamado', function () {
    $a = wlUser('a@wl.test');
    $b = wlUser('b@wl.test');
    $linker = app(WhatsAppLinker::class);
    $linker->claim('5511999990000', $linker->start($a));

    $codeB = $linker->start($b);

    expect($linker->claim('5511999990000', $codeB))->toBeNull()->and($b->fresh()->whatsapp_phone)->toBeNull()->and($linker->userFor('5511999990000')?->id)->toBe($a->id);
});

it('trocar de número exige novo código e desvincula o anterior', function () {
    $u = wlUser();
    $linker = app(WhatsAppLinker::class);
    $linker->claim('5511999990000', $linker->start($u));

    $linker->claim('5511888880000', $linker->start($u));

    expect($u->fresh()->whatsapp_phone)->toBe('5511888880000')->and($linker->userFor('5511999990000'))->toBeNull()->and($linker->userFor('5511888880000')?->id)->toBe($u->id);
});

it('desvincular limpa o número e o verificado', function () {
    $u = wlUser();
    $linker = app(WhatsAppLinker::class);
    $linker->claim('5511999990000', $linker->start($u));

    $linker->unlink($u);

    expect($u->fresh()->whatsapp_phone)->toBeNull()->and($u->fresh()->whatsapp_verified_at)->toBeNull()->and($linker->userFor('5511999990000'))->toBeNull();
});

it('só número verificado identifica o usuário, e o número é normalizado (só dígitos)', function () {
    $u = wlUser();
    $u->forceFill(['whatsapp_phone' => '5511999990000'])->save(); // sem verified_at
    $linker = app(WhatsAppLinker::class);

    expect($linker->userFor('5511999990000'))->toBeNull();
    $u->forceFill(['whatsapp_verified_at' => now()])->save();
    expect($linker->userFor('+55 (11) 99999-0000')?->id)->toBe($u->id);
});

it('o código de um usuário sem o recurso do assistente não vincula', function () {
    $u = wlUser('sem@wl.test', assistant: false);
    $linker = app(WhatsAppLinker::class);
    $code = $linker->start($u);

    expect($linker->claim('5511999990000', $code))->toBeNull();
});

// ------------------------------------------------------------------ respostas no WhatsApp

function wlHandler(?InboundHandler $next = null): LinkingInboundHandler
{
    app(AssistantSettings::class)->saveMeta(['access_token' => 't', 'phone_number_id' => 'PNID123', 'app_secret' => 's', 'verify_token' => 'v']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

    return new LinkingInboundHandler(app(WhatsAppLinker::class), app(\App\Assistant\Channel\MetaWhatsAppClient::class), $next ?? new class implements InboundHandler {
        public array $seen = [];
        public function handle(InboundMessage $message): void { $this->seen[] = $message->waMessageId; }
    });
}

it('número desconhecido sem código recebe só a orientação para vincular, uma vez por hora', function () {
    $h = wlHandler();

    $h->handle(wlMsg('5511999990000', 'oi'));
    $h->handle(wlMsg('5511999990000', 'tem alguém aí?'));

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['to'] === '5511999990000' && str_contains($r['text']['body'], 'Meu perfil'));
    CarbonImmutable::setTestNow('2026-12-10 13:05:00');
    Cache::flush();
    $h->handle(wlMsg('5511999990000', 'oi de novo'));
    Http::assertSentCount(2);
});

it('mensagem com o código vincula e responde a confirmação', function () {
    $u = wlUser();
    $code = app(WhatsAppLinker::class)->start($u);
    $h = wlHandler();

    $h->handle(wlMsg('5511999990000', $code));

    expect($u->fresh()->whatsapp_phone)->toBe('5511999990000');
    Http::assertSent(fn ($r) => $r['to'] === '5511999990000' && str_contains($r['text']['body'], 'vinculado') && str_contains($r['text']['body'], 'Ana'));
});

it('número bloqueado não recebe resposta nenhuma (não vira canal de spam)', function () {
    $linker = app(WhatsAppLinker::class);
    foreach (['AAAA2222', 'BBBB3333', 'CCCC4444', 'DDDD5555', 'EEEE6666'] as $wrong) {
        $linker->claim('5511999990000', $wrong);
    }
    $h = wlHandler();

    $h->handle(wlMsg('5511999990000', 'oi'));

    Http::assertNothingSent();
});

it('número vinculado segue para o tratador seguinte (o assistente)', function () {
    $u = wlUser();
    app(WhatsAppLinker::class)->claim('5511999990000', app(WhatsAppLinker::class)->start($u));
    $next = new class implements InboundHandler {
        public array $seen = [];
        public function handle(InboundMessage $message): void { $this->seen[] = $message->text; }
    };
    $h = wlHandler($next);

    $h->handle(wlMsg('5511999990000', 'quais pendentes?'));

    expect($next->seen)->toBe(['quais pendentes?']);
    Http::assertNothingSent();
});

it('falha ao responder pela Meta não derruba o processamento', function () {
    $h = wlHandler();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'x', 'code' => 190]], 401)]);

    $h->handle(wlMsg('5511999990000', 'oi'));

    expect(true)->toBeTrue();
});

it('o tratador padrão do sistema já inclui o vínculo', function () {
    expect(app(InboundHandler::class))->toBeInstanceOf(LinkingInboundHandler::class);
});

// ------------------------------------------------------------------ perfil

it('o perfil só oferece o cartão do WhatsApp quando o plano tem o assistente', function () {
    $with = wlUser('com@wl.test');
    $without = wlUser('sem@wl.test', assistant: false);

    $this->actingAs($with)->get('/perfil')->assertOk()->assertSee('Assistente no WhatsApp')->assertSee('Gerar código');
    $this->actingAs($without)->get('/perfil')->assertOk()->assertDontSee('Assistente no WhatsApp');
    $this->actingAs($without)->post('/perfil/whatsapp/codigo')->assertForbidden();
});

it('o código aparece uma vez só, na tela logo depois de gerar', function () {
    $u = wlUser();

    $this->actingAs($u)->post('/perfil/whatsapp/codigo')->assertRedirect('/perfil');
    $page = $this->actingAs($u)->get('/perfil');
    $page->assertOk()->assertSee('15 minutos');
    preg_match('/>([ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8})</', $page->getContent(), $m);
    expect($m)->toHaveCount(2);

    $this->actingAs($u)->get('/perfil')->assertDontSee($m[1]); // recarregar não mostra de novo
});

it('número vinculado aparece mascarado e dá para desvincular pelo perfil', function () {
    $u = wlUser();
    app(WhatsAppLinker::class)->claim('5511999990000', app(WhatsAppLinker::class)->start($u));

    $this->actingAs($u->fresh())->get('/perfil')->assertOk()->assertSee('Desvincular')->assertSee('0000')->assertDontSee('5511999990000');
    $this->actingAs($u->fresh())->delete('/perfil/whatsapp')->assertRedirect('/perfil');

    expect($u->fresh()->whatsapp_phone)->toBeNull();
});

it('o perfil mostra o número da plataforma quando configurado, com link para abrir a conversa', function () {
    $u = wlUser();
    app(AssistantSettings::class)->saveMeta(['display_phone' => '5511900001111']);

    $this->actingAs($u)->post('/perfil/whatsapp/codigo');
    $page = $this->actingAs($u)->get('/perfil');

    $page->assertSee('wa.me/5511900001111', false);
});
