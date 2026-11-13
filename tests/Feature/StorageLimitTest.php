<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\TrashService;
use App\Mail\PlanUsageWarningMail;
use App\Models\Envelope;
use App\Models\Plan;
use App\Models\SignerAttachment;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanService;
use App\Plans\StorageUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
});

function stTenant(array $plan = []): Tenant
{
    static $n = 0;
    $n++;
    $p = Plan::create($plan + ['name' => 'S'.$n, 'slug' => 's-'.$n, 'active' => true, 'features' => array_fill_keys(Plan::FEATURES, true)]);
    $t = Tenant::create(['name' => 'Empresa '.$n]);
    app(PlanService::class)->assign($t, $p);

    return $t->fresh();
}

function stPdf(int $bytes): string
{
    return "%PDF-1.4\n".str_repeat('a', max(0, $bytes - 9));
}

function stDraft(Tenant $t, int $bytes = 1000): Envelope
{
    static $k = 0;
    $k++;

    return app(EnvelopeService::class)->create($t, null, stPdf($bytes), 'a.pdf', 'Doc '.$k, [['name' => 'Ana', 'email' => "ana{$k}@ex.com"]]);
}

function stOwner(Tenant $t): User
{
    return User::create(['tenant_id' => $t->id, 'name' => 'Dona', 'email' => 'dona'.$t->id.'@x.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
}

it('cada documento gravado soma ao armazenamento da empresa, e cada remoção definitiva devolve', function () {
    $t = stTenant();
    $a = stDraft($t, 2000);
    stDraft($t, 3000);
    expect($t->fresh()->storage_bytes)->toBe(5000);

    app(TrashService::class)->trash($a->fresh());
    $this->travel(31)->days();
    expect(app(TrashService::class)->purge(30))->toBe(1);
    expect($t->fresh()->storage_bytes)->toBe(3000);
});

it('a conciliação corrige o contador usando os tamanhos gravados (original, anexos e selado)', function () {
    $t = stTenant();
    $e = stDraft($t, 4000);
    $signer = $e->signers()->first();
    SignerAttachment::create(['signer_id' => $signer->id, 'kind' => 'selfie', 'path' => 'x/selfie.jpg', 'mime' => 'image/jpeg', 'size' => 700, 'sha256' => str_repeat('b', 64), 'source' => 'camera']);
    Storage::disk('local')->put('tenants/'.$t->id.'/final/'.$e->id.'.pdf', stPdf(1500));
    $e->update(['final_path' => 'tenants/'.$t->id.'/final/'.$e->id.'.pdf']); // selado antigo, sem final_size

    $t->update(['storage_bytes' => 999999]);
    expect(app(StorageUsage::class)->reconcile($t->fresh()))->toBe(4000 + 700 + 1500)
        ->and($t->fresh()->storage_bytes)->toBe(6200)->and($e->fresh()->final_size)->toBe(1500);
});

it('PDF acima do tamanho do plano e armazenamento estourado barram o novo documento, com mensagem clara', function () {
    $t = stTenant(['limits' => ['storage_mb' => 1, 'max_pdf_mb' => 1]]);

    $reason = function (Closure $fn) {
        try {
            $fn();
        } catch (FlowException $e) {
            return $e->reason;
        }

        return null;
    };

    expect($reason(fn () => stDraft($t, 1024 * 1024 + 10)))->toBe('pdf_too_large');
    stDraft($t, 700 * 1024);
    expect($reason(fn () => stDraft($t, 500 * 1024)))->toBe('storage_limit')
        ->and(Envelope::where('tenant_id', $t->id)->count())->toBe(1); // nada ficou pela metade
});

it('estourado o armazenamento, enviar também é barrado, mas assinar e baixar seguem livres', function () {
    $t = stTenant(['limits' => ['storage_mb' => 1]]);
    $first = stDraft($t, 300 * 1024);
    $second = stDraft($t, 300 * 1024);
    app(EnvelopeService::class)->send($first); // ainda abaixo do limite
    $t->update(['storage_bytes' => 2 * 1024 * 1024]); // estourou (selagens e anexos somam)

    try {
        app(EnvelopeService::class)->send($second);
        $reason = null;
    } catch (FlowException $e) {
        $reason = $e->reason;
    }
    expect($reason)->toBe('storage_limit')->and($second->fresh()->status->value)->toBe('draft');

    $this->get($first->signers()->first()->fresh()->signUrl())->assertOk(); // quem já recebeu continua assinando
});

it('a faixa aparece a 80% e a 100% e some no Legado', function () {
    $t = stTenant(['limits' => ['documents' => 5]]);
    $user = stOwner($t);
    $this->actingAs($user);

    $this->get('/painel')->assertOk()->assertDontSee('Você já usou');
    foreach (range(1, 4) as $i) {
        app(EnvelopeService::class)->send(stDraft($t));
    }
    $this->get('/painel')->assertOk()->assertSee('Você já usou 80%')->assertSee('documentos');
    app(EnvelopeService::class)->send(stDraft($t));
    $this->get('/painel')->assertOk()->assertSee('limite')->assertSee('100%');

    $legacy = Tenant::create(['name' => 'Antiga']);
    app(PlanService::class)->backfillLegacy();
    $this->actingAs(stOwner($legacy))->get('/painel')->assertOk()->assertDontSee('Você já usou');
});

it('o e-mail de aviso sai uma vez ao chegar em 80% e uma vez ao chegar em 100%', function () {
    $t = stTenant(['limits' => ['documents' => 5]]);
    stOwner($t);

    foreach (range(1, 3) as $i) {
        app(EnvelopeService::class)->send(stDraft($t));
    }
    Mail::assertNotSent(PlanUsageWarningMail::class);

    app(EnvelopeService::class)->send(stDraft($t)); // 4 de 5 = 80%
    Mail::assertSent(PlanUsageWarningMail::class, 1);

    app(EnvelopeService::class)->send(stDraft($t)); // 5 de 5 = 100%
    Mail::assertSent(PlanUsageWarningMail::class, 2);
    Mail::assertSent(PlanUsageWarningMail::class, fn (PlanUsageWarningMail $m) => $m->full === true);
});
