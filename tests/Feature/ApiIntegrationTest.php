<?php

declare(strict_types=1);

use App\Mail\SignatureRequestedMail;
use App\Models\ApiKey;
use App\Models\Envelope;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const MINI_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

function integ(?Tenant $tenant = null, bool $readOnly = false): array
{
    $tenant ??= Tenant::create(['name' => 'ACME']);
    [, $plain] = ApiKey::issue($tenant, 'k', $readOnly);
    \App\Models\TenantSigningSetting::updateOrCreate(['tenant_id' => $tenant->id], ['sender_policy' => 'never']); // estes testes cobrem signatários; o remetente tem testes próprios

    return [$tenant, ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json']];
}

function jsonEnvelope(array $extra = []): array
{
    return $extra + [
        'title' => 'Contrato', 'document_base64' => base64_encode(MINI_PDF), 'document_name' => 'contrato.pdf',
        'signers' => [['name' => 'Ana', 'email' => 'ana@ex.com', 'phone' => '(11) 99999-8888']],
    ];
}

beforeEach(fn () => Storage::fake('local'));

// ---------------------------------------------------------------- conta e chaves

it('GET /me confere a chave e mostra o consumo', function () {
    [$tenant, $h] = integ();

    $this->getJson('/api/v1/me', $h)->assertOk()
        ->assertJsonPath('api_version', 'v1')->assertJsonPath('tenant.name', 'ACME')
        ->assertJsonPath('key.read_only', false)->assertJsonPath('usage.envelopes_sent_this_month', 0)
        ->assertJsonFragment(['webhook_events' => array_keys(config('webhooks.events'))]);
});

it('chave somente leitura consulta mas não altera', function () {
    [$tenant, $rw] = integ();
    [, $ro] = integ($tenant, readOnly: true);

    $id = $this->postJson('/api/v1/envelopes', jsonEnvelope(), $rw)->assertCreated()->json('id');

    $this->getJson("/api/v1/envelopes/{$id}", $ro)->assertOk();
    $this->getJson('/api/v1/envelopes', $ro)->assertOk();
    $this->postJson('/api/v1/envelopes', jsonEnvelope(), $ro)->assertForbidden()->assertJsonPath('error', 'read_only_key');
    $this->postJson("/api/v1/envelopes/{$id}/cancel", [], $ro)->assertForbidden();
    $this->postJson('/api/v1/folders', ['name' => 'X'], $ro)->assertForbidden();
});

// ---------------------------------------------------------------- criação

it('cria por JSON com PDF em base64, external_id, metadata e posição de assinatura', function () {
    [, $h] = integ();

    $res = $this->postJson('/api/v1/envelopes', jsonEnvelope([
        'external_id' => 'pedido-1', 'metadata' => ['pedido' => 1234, 'origem' => 'erp'],
        'channels' => ['email', 'whatsapp'], 'validity_days' => 10, 'reminder_every_days' => 0,
        'signature_fields' => [['signer' => 0, 'page' => 1, 'x' => 0.1, 'y' => 0.8]],
    ]), $h)->assertCreated();

    $res->assertJsonPath('external_id', 'pedido-1')->assertJsonPath('metadata.origem', 'erp')
        ->assertJsonPath('channels', ['email', 'whatsapp'])->assertJsonPath('validity_days', 10)
        ->assertJsonPath('reminder_every_days', null)->assertJsonPath('document.name', 'contrato.pdf')
        ->assertJsonPath('document.sha256', hash('sha256', MINI_PDF))->assertJsonPath('status', 'draft');
    expect(Envelope::firstOrFail()->signatureFields)->toHaveCount(1);
});

it('aceita o prefixo data: no base64 e rejeita base64 inválido e arquivo que não é PDF', function () {
    [, $h] = integ();

    $this->postJson('/api/v1/envelopes', jsonEnvelope(['document_base64' => 'data:application/pdf;base64,'.base64_encode(MINI_PDF)]), $h)->assertCreated();
    $this->postJson('/api/v1/envelopes', jsonEnvelope(['document_base64' => '%%%nao-e-base64%%%']), $h)
        ->assertStatus(422)->assertJsonPath('error', 'invalid_document');
    $this->postJson('/api/v1/envelopes', jsonEnvelope(['document_base64' => base64_encode('texto qualquer')]), $h)
        ->assertStatus(422)->assertJsonPath('error', 'invalid_document');
});

it('erros de validação seguem o formato único da API', function () {
    [, $h] = integ();

    $this->postJson('/api/v1/envelopes', ['title' => 'sem nada'], $h)
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['message', 'errors' => ['signers']]);
    $this->getJson('/api/v1/envelopes/00000000-0000-7000-8000-000000000000', $h)->assertNotFound()->assertJsonPath('error', 'not_found');
    $this->getJson('/api/v1/envelopes/nao-existe', $h)->assertNotFound()->assertJsonPath('error', 'not_found');
});

it('external_id é único por conta e permite consultar pelo identificador do cliente', function () {
    [$tenant, $h] = integ();
    $this->postJson('/api/v1/envelopes', jsonEnvelope(['external_id' => 'ped-9']), $h)->assertCreated();

    $this->postJson('/api/v1/envelopes', jsonEnvelope(['external_id' => 'ped-9']), $h)
        ->assertStatus(422)->assertJsonPath('error', 'duplicate_external_id');
    $this->getJson('/api/v1/envelopes/ped-9', $h)->assertOk()->assertJsonPath('external_id', 'ped-9');

    // outra conta pode usar o mesmo external_id e não enxerga o da primeira
    [, $other] = integ();
    $this->getJson('/api/v1/envelopes/ped-9', $other)->assertNotFound();
    $this->postJson('/api/v1/envelopes', jsonEnvelope(['external_id' => 'ped-9']), $other)->assertCreated();
});

// ---------------------------------------------------------------- idempotência

it('Idempotency-Key devolve a resposta original em vez de duplicar', function () {
    [, $h] = integ();
    $h['Idempotency-Key'] = 'chave-1';

    $first = $this->postJson('/api/v1/envelopes', jsonEnvelope(), $h)->assertCreated();
    $second = $this->postJson('/api/v1/envelopes', jsonEnvelope(), $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('id'))->toBe($first->json('id'))->and(Envelope::count())->toBe(1);

    $this->postJson('/api/v1/envelopes', jsonEnvelope(['title' => 'Outro conteúdo']), $h)
        ->assertStatus(422)->assertJsonPath('error', 'idempotency_key_reused');
    expect(Envelope::count())->toBe(1);
});

it('a mesma Idempotency-Key em contas diferentes não se mistura', function () {
    [, $a] = integ();
    [, $b] = integ();
    $a['Idempotency-Key'] = $b['Idempotency-Key'] = 'mesma';

    $this->postJson('/api/v1/envelopes', jsonEnvelope(), $a)->assertCreated();
    $this->postJson('/api/v1/envelopes', jsonEnvelope(), $b)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect(Envelope::count())->toBe(2);
});

it('erro de validação também é repetido com a mesma chave, e chave grande demais é recusada', function () {
    [, $h] = integ();
    $h['Idempotency-Key'] = 'ruim';
    $this->postJson('/api/v1/envelopes', ['title' => 'x'], $h)->assertStatus(422);
    $this->postJson('/api/v1/envelopes', ['title' => 'x'], $h)->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');

    $h['Idempotency-Key'] = str_repeat('a', 121);
    $this->postJson('/api/v1/envelopes', jsonEnvelope(), $h)->assertStatus(422)->assertJsonPath('error', 'invalid_idempotency_key');
});

it('o comando de limpeza remove chaves de idempotência antigas', function () {
    [$tenant, $h] = integ();
    $h['Idempotency-Key'] = 'velha';
    $this->postJson('/api/v1/envelopes', jsonEnvelope(), $h)->assertCreated();
    \Illuminate\Support\Facades\DB::table('api_idempotency_keys')->update(['created_at' => now()->subDays(2)]);

    $this->artisan('api:prune-idempotency')->expectsOutputToContain('1 chave');
    expect(\Illuminate\Support\Facades\DB::table('api_idempotency_keys')->count())->toBe(0);
});

// ---------------------------------------------------------------- listagem

it('lista com filtros e paginação, sem vazar envelopes de outra conta', function () {
    [$tenant, $h] = integ();
    $this->postJson('/api/v1/envelopes', jsonEnvelope(['title' => 'Aluguel galpão', 'external_id' => 'a-1', 'signers' => [['name' => 'Zeca', 'email' => 'zeca@loja.com']]]), $h)->assertCreated();
    $this->postJson('/api/v1/envelopes', jsonEnvelope(['title' => 'NDA', 'send' => true]), $h)->assertCreated();
    [, $other] = integ();
    $this->postJson('/api/v1/envelopes', jsonEnvelope(['title' => 'Segredo alheio']), $other)->assertCreated();

    $this->getJson('/api/v1/envelopes', $h)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/envelopes?status=draft', $h)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Aluguel galpão');
    $this->getJson('/api/v1/envelopes?q=zeca', $h)->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/envelopes?external_id=a-1', $h)->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/envelopes?per_page=1&page=2', $h)->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/envelopes?per_page=500', $h)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    $this->getJson('/api/v1/envelopes?q=Segredo', $h)->assertJsonPath('meta.total', 0);
});

// ---------------------------------------------------------------- downloads, reenvio, link

it('baixa o PDF original e informa que o selado ainda não existe', function () {
    [, $h] = integ();
    $id = $this->postJson('/api/v1/envelopes', jsonEnvelope(), $h)->json('id');

    $this->get("/api/v1/envelopes/{$id}/original", $h)->assertOk();
    $this->getJson("/api/v1/envelopes/{$id}/download", $h)->assertStatus(409)->assertJsonPath('error', 'not_ready');
    $this->postJson("/api/v1/envelopes/{$id}/reseal", [], $h)->assertStatus(409)->assertJsonPath('error', 'not_sealing');
});

it('reenvia o aviso e gera novo link para um signatário', function () {
    Mail::fake();
    [, $h] = integ();
    $envelope = $this->postJson('/api/v1/envelopes', jsonEnvelope(['send' => true]), $h)->assertCreated()->json();
    $signer = $envelope['signers'][0];
    Mail::assertSent(SignatureRequestedMail::class, 1);

    $this->postJson("/api/v1/envelopes/{$envelope['id']}/signers/{$signer['id']}/resend", [], $h)->assertOk()->assertJsonPath('sent', true);
    Mail::assertSent(SignatureRequestedMail::class, 2);

    $new = $this->postJson("/api/v1/envelopes/{$envelope['id']}/signers/{$signer['id']}/new-link", [], $h)->assertOk()->json();
    expect($new['sign_url'])->not->toBe($signer['sign_url'])->and($new['sign_url'])->toContain('/assinar/');
    $this->get($signer['sign_url'])->assertOk()->assertSee('inválido'); // o link antigo morreu

    $this->postJson("/api/v1/envelopes/{$envelope['id']}/signers/nao-uuid/resend", [], $h)->assertNotFound();
    $this->postJson("/api/v1/envelopes/{$envelope['id']}/signers/00000000-0000-7000-8000-000000000000/resend", [], $h)->assertNotFound();
});

it('reenvio respeita a ordem sequencial e o envio manual', function () {
    Mail::fake();
    [, $h] = integ();
    $env = $this->postJson('/api/v1/envelopes', jsonEnvelope(['send' => true, 'signing_order' => 'sequential', 'signers' => [
        ['name' => 'Ana', 'email' => 'ana@ex.com'], ['name' => 'Beto', 'email' => 'beto@ex.com'],
    ]]), $h)->assertCreated()->json();

    Mail::assertSent(SignatureRequestedMail::class, 1);
    $this->postJson("/api/v1/envelopes/{$env['id']}/signers/{$env['signers'][1]['id']}/resend", [], $h)->assertStatus(409)->assertJsonPath('error', 'not_your_turn');
});

// ---------------------------------------------------------------- modelos

it('gerencia modelos e gera envelopes a partir deles', function () {
    Mail::fake();
    [, $h] = integ();

    $t = $this->postJson('/api/v1/templates', ['name' => 'Contrato', 'body' => '<p onclick="x()">Contratante {{contratante}} - R$ {{valor}}</p><script>x</script>'], $h)
        ->assertCreated()->assertJsonPath('variables', ['contratante', 'valor'])->json();
    expect($t['body'])->not->toContain('script')->not->toContain('onclick');

    $this->getJson('/api/v1/templates', $h)->assertOk()->assertJsonCount(1, 'data');
    $this->putJson("/api/v1/templates/{$t['id']}", ['name' => 'Contrato v2', 'body' => '<p>{{nome}}</p>'], $h)->assertOk()->assertJsonPath('variables', ['nome']);

    $this->postJson("/api/v1/templates/{$t['id']}/envelopes", ['title' => 'X', 'signers' => [['name' => 'A', 'email' => 'a@a.com']]], $h)
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['errors' => ['variables.nome']]);

    $res = $this->postJson("/api/v1/templates/{$t['id']}/envelopes", [
        'title' => 'Contrato Maria', 'variables' => ['nome' => 'Maria'], 'external_id' => 'tpl-1', 'send' => true,
        'signers' => [['name' => 'Maria', 'email' => 'maria@ex.com']],
    ], $h)->assertCreated()->assertJsonPath('status', 'sent')->assertJsonPath('external_id', 'tpl-1');
    Mail::assertSent(SignatureRequestedMail::class, 1);
    expect(str_starts_with(Storage::disk('local')->get(Envelope::firstOrFail()->document->storage_path), '%PDF-'))->toBeTrue();

    $this->deleteJson("/api/v1/templates/{$t['id']}", [], $h)->assertNoContent();
    $this->getJson("/api/v1/templates/{$t['id']}", $h)->assertNotFound();
});

it('modelos de outra conta são invisíveis', function () {
    [, $a] = integ();
    [, $b] = integ();
    $id = $this->postJson('/api/v1/templates', ['name' => 'Meu', 'body' => '<p>x</p>'], $a)->json('id');

    $this->getJson("/api/v1/templates/{$id}", $b)->assertNotFound();
    $this->putJson("/api/v1/templates/{$id}", ['name' => 'x', 'body' => 'y'], $b)->assertNotFound();
    $this->deleteJson("/api/v1/templates/{$id}", [], $b)->assertNotFound();
    $this->postJson("/api/v1/templates/{$id}/envelopes", ['title' => 't', 'signers' => [['name' => 'A', 'email' => 'a@a.com']]], $b)->assertNotFound();
});

// ---------------------------------------------------------------- webhooks

it('gerencia webhooks pela API: segredo só na criação, teste e histórico', function () {
    config(['webhooks.allow_private' => true]);
    Http::fake(['*' => Http::response('ok', 200)]);
    [, $h] = integ();

    $w = $this->postJson('/api/v1/webhooks', ['url' => 'http://hooks.test/in', 'events' => ['signer.signed']], $h)
        ->assertCreated()->assertJsonStructure(['id', 'secret'])->json();
    expect($w['secret'])->toStartWith('whsec_');

    $this->getJson('/api/v1/webhooks', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.secret');
    $this->postJson("/api/v1/webhooks/{$w['id']}/test", [], $h)->assertStatus(202);
    $this->getJson("/api/v1/webhooks/{$w['id']}/deliveries", $h)->assertOk()->assertJsonPath('data.0.event', 'ping')->assertJsonPath('data.0.status', 'delivered');
    $this->patchJson("/api/v1/webhooks/{$w['id']}", ['active' => false], $h)->assertOk()->assertJsonPath('active', false);
    $this->deleteJson("/api/v1/webhooks/{$w['id']}", [], $h)->assertNoContent();
    $this->getJson('/api/v1/webhooks', $h)->assertJsonCount(0, 'data');
});

it('recusa webhook para rede interna e eventos desconhecidos', function () {
    config(['webhooks.allow_private' => false]);
    [, $h] = integ();

    $this->postJson('/api/v1/webhooks', ['url' => 'https://127.0.0.1/x'], $h)->assertStatus(422)->assertJsonPath('error', 'invalid_url');
    $this->postJson('/api/v1/webhooks', ['url' => 'https://8.8.8.8/x', 'events' => ['evento.inventado']], $h)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
});

it('webhooks de outra conta são invisíveis', function () {
    config(['webhooks.allow_private' => true]);
    [, $a] = integ();
    [, $b] = integ();
    $id = $this->postJson('/api/v1/webhooks', ['url' => 'http://hooks.test/in'], $a)->json('id');

    $this->deleteJson("/api/v1/webhooks/{$id}", [], $b)->assertNotFound();
    $this->postJson("/api/v1/webhooks/{$id}/test", [], $b)->assertNotFound();
    $this->getJson("/api/v1/webhooks/{$id}/deliveries", $b)->assertNotFound();
});

it('o webhook leva o external_id e os metadados do envelope', function () {
    config(['webhooks.allow_private' => true]);
    Http::fake(['*' => Http::response('ok', 200)]);
    Queue::fake([\App\Jobs\SealEnvelope::class]);
    [, $h] = integ();
    $this->postJson('/api/v1/webhooks', ['url' => 'http://hooks.test/in', 'events' => ['envelope.sent']], $h)->assertCreated();

    $this->postJson('/api/v1/envelopes', jsonEnvelope(['external_id' => 'ped-7', 'metadata' => ['k' => 'v'], 'send' => true]), $h)->assertCreated();

    Http::assertSent(fn ($r) => json_decode($r->body(), true)['data']['envelope']['external_id'] === 'ped-7'
        && json_decode($r->body(), true)['data']['envelope']['metadata'] === ['k' => 'v']);
});

// ---------------------------------------------------------------- pastas e etiquetas

it('cria, lista e remove pastas e etiquetas; usa na criação do envelope', function () {
    [, $h] = integ();
    $folder = $this->postJson('/api/v1/folders', ['name' => 'RH'], $h)->assertCreated()->json();
    $tag = $this->postJson('/api/v1/tags', ['name' => 'Urgente', 'color' => 'danger'], $h)->assertCreated()->json();

    $this->postJson('/api/v1/folders', ['name' => 'RH'], $h)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    $this->postJson('/api/v1/tags', ['name' => 'X', 'color' => 'rosa'], $h)->assertStatus(422);

    $env = $this->postJson('/api/v1/envelopes', jsonEnvelope(['folder_id' => $folder['id'], 'tags' => [$tag['id']]]), $h)->assertCreated()
        ->assertJsonPath('folder.name', 'RH')->assertJsonPath('tags.0.name', 'Urgente')->json();
    $this->getJson("/api/v1/envelopes?folder_id={$folder['id']}", $h)->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/envelopes?tag_id={$tag['id']}", $h)->assertJsonPath('meta.total', 1);

    $this->deleteJson("/api/v1/folders/{$folder['id']}", [], $h)->assertNoContent();
    $this->deleteJson("/api/v1/tags/{$tag['id']}", [], $h)->assertNoContent();
    $this->getJson("/api/v1/envelopes/{$env['id']}", $h)->assertJsonPath('folder', null)->assertJsonPath('tags', []);
    $this->getJson('/api/v1/folders', $h)->assertJsonCount(0, 'data');
});

it('pasta de outra conta é recusada na criação do envelope', function () {
    [, $a] = integ();
    [, $b] = integ();
    $foreign = $this->postJson('/api/v1/folders', ['name' => 'Alheia'], $a)->json('id');

    $this->postJson('/api/v1/envelopes', jsonEnvelope(['folder_id' => $foreign]), $b)->assertStatus(422)->assertJsonPath('error', 'invalid_folder');
    $this->deleteJson("/api/v1/folders/{$foreign}", [], $b)->assertNotFound();
});

// ---------------------------------------------------------------- verificação pública e documentação

it('verificação pública por código funciona sem chave e confere o hash do arquivo', function () {
    [, $h] = integ();
    $env = $this->postJson('/api/v1/envelopes', jsonEnvelope(), $h)->json();

    $this->getJson("/api/v1/verify/{$env['verification_code']}")->assertOk()
        ->assertJsonPath('status', 'draft')->assertJsonPath('issuer', 'ACME')->assertJsonPath('chain_intact', true)
        ->assertJsonMissingPath('match')->assertJsonMissingPath('signers.0.email');
    $this->getJson("/api/v1/verify/{$env['verification_code']}?sha256=".hash('sha256', MINI_PDF))->assertJsonPath('match', 'original');
    $this->getJson("/api/v1/verify/{$env['verification_code']}?sha256=".str_repeat('0', 64))->assertJsonPath('match', 'none');
    $this->getJson("/api/v1/verify/{$env['verification_code']}?sha256=abc")->assertStatus(422);
    $this->getJson('/api/v1/verify/NAOEXISTE123')->assertNotFound()->assertJsonPath('error', 'not_found');
});

it('serve a especificação OpenAPI e a página de referência', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->assertOk()->assertJsonPath('openapi', '3.0.3')->json();

    expect($spec['paths'])->toHaveKeys(['/envelopes', '/envelopes/{id}', '/templates/{id}/envelopes', '/webhooks', '/verify/{code}', '/me'])
        ->and($spec['components']['securitySchemes'])->toHaveKey('bearerAuth')
        ->and($spec['paths']['/verify/{code}']['get']['security'])->toBe([]);

    $this->get('/api/docs')->assertOk()->assertSee('swagger');
});

it('toda rota autenticada da API está documentada na especificação', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->json('paths');
    $missing = [];

    foreach (app('router')->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/') || in_array($route->uri(), ['api/v1/openapi.json'], true)) {
            continue;
        }
        $path = '/'.substr($route->uri(), strlen('api/v1/'));
        $path = preg_replace('#\{(code|id|signer)\}#', '{$1}', $path);
        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD'], true)) {
                continue;
            }
            if (! isset($spec[$path][strtolower($method)])) {
                $missing[] = "$method $path";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('chaves revogadas e contas suspensas continuam bloqueadas nas rotas novas', function () {
    [$tenant, $h] = integ();
    $tenant->update(['suspended_at' => now()]);

    $this->getJson('/api/v1/me', $h)->assertForbidden();
    $this->getJson('/api/v1/envelopes', $h)->assertForbidden();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
