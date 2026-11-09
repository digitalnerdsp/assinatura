<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Envelopes\EnvelopeService;
use App\Envelopes\SigningService;
use App\Jobs\SealEnvelope;
use App\Models\Tenant;
use App\Models\User;
use App\Signing\Data\SealRequest;
use App\Signing\Exceptions\SigningException;
use App\Signing\Support\SealerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('inclui o motivo devolvido pelo serviço na mensagem de erro', function () {
    HttpFacade::fake(['sealer/*' => HttpFacade::response(['detail' => 'ValueError: pdf inválido'], 500)]);
    $client = new SealerClient(app(Http::class), 'http://sealer', 't');

    expect(fn () => $client->seal('x', ['type' => 'self_signed_test'], new SealRequest('%PDF')))
        ->toThrow(SigningException::class, 'HTTP 500): ValueError: pdf inválido');
});

it('mostra o motivo quando o serviço está fora do ar', function () {
    HttpFacade::fake(['sealer/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('Could not resolve host: sealer')]);
    $client = new SealerClient(app(Http::class), 'http://sealer', 't');

    expect(fn () => $client->health())->toThrow(SigningException::class, 'Could not resolve host');
});

it('permite tentar selar de novo um envelope preso em Selando', function () {
    Storage::fake('local');
    Queue::fake();
    $tenant = Tenant::create(['name' => 'ACME']);
    $user = User::create(['tenant_id' => $tenant->id, 'name' => 'D', 'email' => 'd@acme.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $svc = app(EnvelopeService::class);
    $envelope = $svc->create($tenant, $user, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'C', [['name' => 'Ana', 'email' => 'ana@ex.com']]);
    $svc->send($envelope);
    app(SigningService::class)->sign($envelope->signers()->first(), 'typed', 'Ana', true);
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Sealing);
    Queue::assertPushed(SealEnvelope::class, 1);

    $this->actingAs($user)->post("/envelopes/{$envelope->id}/selar")->assertRedirect()->assertSessionHas('status');
    Queue::assertPushed(SealEnvelope::class, 2);

    $this->get("/envelopes/{$envelope->id}")->assertOk()->assertSee('Tentar selar novamente');
});

it('o painel admin testa a conexão com o serviço de selagem', function () {
    config(['platform.super_admins' => ['admin@plataforma.com']]);
    $admin = User::create(['tenant_id' => Tenant::create(['name' => 'Op'])->id, 'name' => 'A', 'email' => 'admin@plataforma.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);

    HttpFacade::fake(['*/health' => HttpFacade::response(['status' => 'ok']), '*/v1/certificate' => HttpFacade::response([
        'serial' => 'ab12', 'issuer' => 'AC Teste', 'subject' => 'CN=Selo de Teste', 'valid_from' => '2026-01-01T00:00:00+00:00',
        'valid_to' => '2030-01-01T00:00:00+00:00', 'icp_brasil' => false,
    ])]);

    $this->actingAs($admin)->get('/admin/selagem')->assertOk()->assertSee('Testar conexão');
    $this->post('/admin/selagem/teste')->assertRedirect('/admin/selagem');
    $this->get('/admin/selagem')->assertSee('Serviço de selagem responde')->assertSee('SEM validade ICP-Brasil');
});

it('o teste do painel mostra o erro quando o token é recusado', function () {
    config(['platform.super_admins' => ['admin@plataforma.com']]);
    $admin = User::create(['tenant_id' => Tenant::create(['name' => 'Op'])->id, 'name' => 'A', 'email' => 'admin@plataforma.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    HttpFacade::fake(['*' => HttpFacade::response(['detail' => 'não autorizado'], 401)]);

    $this->actingAs($admin)->post('/admin/selagem/teste');
    $this->get('/admin/selagem')->assertSee('HTTP 401')->assertSee('não autorizado');
});

it('só super admin acessa o diagnóstico de selagem', function () {
    $user = User::create(['tenant_id' => Tenant::create(['name' => 'X'])->id, 'name' => 'U', 'email' => 'u@x.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $this->actingAs($user)->get('/admin/selagem')->assertNotFound();
    $this->post('/admin/selagem/teste')->assertNotFound();
});
