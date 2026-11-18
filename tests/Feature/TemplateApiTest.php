<?php

declare(strict_types=1);

use App\Mail\SignatureRequestedMail;
use App\Models\Envelope;
use App\Templates\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function apiTemplate(\App\Models\Tenant $tenant): \App\Models\DocumentTemplate
{
    $tpl = tplCreate($tenant);
    app(TemplateService::class)->syncFields($tpl, [
        tplField(['label' => 'Nome do contratante', 'y' => 0.1]),
        tplField(['label' => 'Valor', 'y' => 0.2, 'required' => false]),
    ]);
    useSealer();

    return $tpl->fresh();
}

it('lista e consulta modelos com as lacunas (chave, nome, página) e sem o corpo HTML', function () {
    [$tenant, $h] = integ();
    $tpl = apiTemplate($tenant);

    $this->getJson('/api/v1/templates', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tpl->id);
    $res = $this->getJson("/api/v1/templates/{$tpl->id}", $h)->assertOk()
        ->assertJsonPath('name', 'Contrato de aluguel')->assertJsonPath('page_count', 2)
        ->assertJsonPath('fields.0', ['key' => 'nome_do_contratante', 'label' => 'Nome do contratante', 'page' => 1, 'multiline' => false, 'required' => true])
        ->assertJsonPath('fields.1.key', 'valor')->assertJsonPath('fields.1.required', false);
    expect($res->json())->not->toHaveKey('body')->not->toHaveKey('variables');
});

it('criar e editar modelos pela API responde 410 com orientação, e remover continua', function () {
    [$tenant, $h] = integ();
    $tpl = apiTemplate($tenant);

    $this->postJson('/api/v1/templates', ['name' => 'X'], $h)->assertStatus(410)->assertJsonPath('error', 'templates_ui_only');
    $this->putJson("/api/v1/templates/{$tpl->id}", ['name' => 'X'], $h)->assertStatus(410)->assertJsonPath('error', 'templates_ui_only');
    $this->deleteJson("/api/v1/templates/{$tpl->id}", [], $h)->assertNoContent();
    $this->getJson("/api/v1/templates/{$tpl->id}", $h)->assertNotFound();
    Storage::disk('local')->assertMissing($tpl->pdf_path);
});

it('gera o documento com as variáveis pelas chaves das lacunas, cria o envelope com o modelo e envia', function () {
    Mail::fake();
    [$tenant, $h] = integ();
    $tpl = apiTemplate($tenant);
    $http = useSealer();

    $res = $this->postJson("/api/v1/templates/{$tpl->id}/documents", [
        'title' => 'Contrato Maria', 'variables' => ['nome_do_contratante' => 'Maria'], 'external_id' => 'tpl-1', 'send' => true,
        'signers' => [['name' => 'Maria', 'email' => 'maria@ex.com']],
    ], $h)->assertCreated()->assertJsonPath('status', 'sent')->assertJsonPath('external_id', 'tpl-1');

    Mail::assertSent(SignatureRequestedMail::class, 1);
    $e = Envelope::firstOrFail();
    expect($e->template_id)->toBe($tpl->id)->and(Storage::disk('local')->get($e->document->storage_path))->toBe('%PDF-1.4 preenchido');
    $http->assertSent(fn ($r) => str_contains($r->body(), '"text":"Maria"') && ! str_contains($r->body(), '"key":"valor"')); // lacuna opcional em branco não é enviada
});

it('variável obrigatória faltando devolve 422 com a chave', function () {
    [$tenant, $h] = integ();
    $tpl = apiTemplate($tenant);

    $this->postJson("/api/v1/templates/{$tpl->id}/documents", ['title' => 'X', 'variables' => [], 'signers' => [['name' => 'A', 'email' => 'a@a.com']]], $h)
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['errors' => ['variables.nome_do_contratante']]);
    $this->postJson("/api/v1/templates/{$tpl->id}/documents", ['title' => 'X', 'variables' => ['nome_do_contratante' => str_repeat('a', 501)], 'signers' => [['name' => 'A', 'email' => 'a@a.com']]], $h)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['variables.nome_do_contratante']]);
    expect(Envelope::count())->toBe(0);
});

it('texto que não cabe devolve 422 does_not_fit com o campo', function () {
    [$tenant, $h] = integ();
    $tpl = apiTemplate($tenant);
    useSealer(\Illuminate\Http\Client\Factory::response(['error' => 'does_not_fit', 'field' => 'nome_do_contratante'], 422));

    $this->postJson("/api/v1/templates/{$tpl->id}/documents", ['title' => 'X', 'variables' => ['nome_do_contratante' => 'x'], 'signers' => [['name' => 'A', 'email' => 'a@a.com']]], $h)
        ->assertStatus(422)->assertJsonPath('error', 'does_not_fit')->assertJsonPath('field', 'nome_do_contratante');
    expect(Envelope::count())->toBe(0);
});

it('modelos de outra conta são invisíveis em todas as rotas', function () {
    [$ta, $a] = integ();
    [, $b] = integ();
    $tpl = apiTemplate($ta);

    $this->getJson("/api/v1/templates/{$tpl->id}", $b)->assertNotFound();
    $this->deleteJson("/api/v1/templates/{$tpl->id}", [], $b)->assertNotFound();
    $this->postJson("/api/v1/templates/{$tpl->id}/documents", ['title' => 't', 'variables' => ['nome_do_contratante' => 'x'], 'signers' => [['name' => 'A', 'email' => 'a@a.com']]], $b)->assertNotFound();
    $this->getJson('/api/v1/templates', $b)->assertOk()->assertJsonCount(0, 'data');
});

it('o OpenAPI descreve as lacunas e marca criar e editar modelos como descontinuados', function () {
    $json = json_encode(\App\Api\OpenApiSpec::build('http://x'), JSON_UNESCAPED_UNICODE);

    expect($json)->toContain('fields')->and($json)->toContain('Descontinuado')->and($json)->not->toContain('HTML simples');
});
