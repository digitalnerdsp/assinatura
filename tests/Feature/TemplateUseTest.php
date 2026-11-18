<?php

declare(strict_types=1);

use App\Models\DocumentTemplate;
use App\Models\Envelope;
use App\Models\Folder;
use App\Signing\Support\SealerClient;
use App\Templates\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

/** Selador falso que devolve um PDF "preenchido" (ou 422 conforme $reply) e guarda as chamadas para conferir. */
function useSealer($reply = null): Http
{
    $http = new Http();
    $http->fake(['sealer/v1/fill' => $reply ?? Http::response('%PDF-1.4 preenchido', 200, ['X-Page-Count' => '2'])]);
    app()->instance(SealerClient::class, new SealerClient($http, 'http://sealer', 't'));

    return $http;
}

/** Modelo pronto para usar, com 3 lacunas: Nome (obrigatória), Endereço (várias linhas) e Observação (opcional). */
function useTemplate(\App\Models\User $u): DocumentTemplate
{
    $tpl = tplCreate($u->tenant);
    app(TemplateService::class)->syncFields($tpl, [
        tplField(['label' => 'Nome do contratante', 'y' => 0.10]),
        tplField(['label' => 'Endereço', 'y' => 0.20, 'height' => 0.1, 'multiline' => true]),
        tplField(['label' => 'Observação', 'y' => 0.40, 'required' => false]),
    ]);
    useSealer();

    return $tpl->fresh();
}

function useBody(array $over = []): array
{
    return $over + ['title' => 'Contrato da Ana', 'values' => ['nome_do_contratante' => 'Ana Souza', 'endereco' => "Rua A, 1\nCentro", 'observacao' => '']];
}

it('o formulário traz um campo para cada lacuna, na ordem de leitura, com obrigatório marcado', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);

    $this->actingAs($u)->get("/modelos/{$tpl->id}/usar")->assertOk()
        ->assertSeeInOrder(['Nome do contratante', 'Endereço', 'Observação'])
        ->assertSee('name="values[nome_do_contratante]"', false)->assertSee('<textarea', false)->assertSee('name="title"', false)
        ->assertSee('Contrato de aluguel')->assertSee('Ver prévia')->assertDontSee('{{');
});

it('modelo sem lacunas pede para marcá-las antes de usar', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);

    $this->actingAs($u)->get("/modelos/{$tpl->id}/usar")->assertOk()->assertSee('Marque ao menos uma lacuna');
    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody())->assertSessionHasErrors('values');
    expect(Envelope::count())->toBe(0);
});

it('gera o PDF preenchido, cria o rascunho no passo 1 com o modelo e leva ao passo 2', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    $folder = Folder::create(['tenant_id' => $u->tenant_id, 'name' => 'Jurídico']);
    $http = useSealer();

    $res = $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody(['folder_id' => $folder->id]));
    $e = Envelope::firstOrFail();

    $res->assertRedirect(route('envelopes.step', [$e, 2]));
    expect($e->title)->toBe('Contrato da Ana')->and($e->status->value)->toBe('draft')->and((int) $e->wizard_step)->toBe(1)->and($e->template_id)->toBe($tpl->id)
        ->and($e->folder_id)->toBe($folder->id)->and($e->created_by)->toBe($u->id)->and(Storage::disk('local')->get($e->document->storage_path))->toBe('%PDF-1.4 preenchido');
    $http->assertSent(function ($r) {
        $body = $r->body();

        return str_contains($body, '"key":"nome_do_contratante"') && str_contains($body, '"text":"Ana Souza"') && str_contains($body, '"multiline":true')
            && str_contains($body, '"page":1') && str_contains($body, 'Centro');
    });
});

it('a prévia devolve o PDF preenchido e não cria nada', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    useSealer();

    $res = $this->actingAs($u)->post("/modelos/{$tpl->id}/previa", useBody())->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('application/pdf')->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($res->getContent())->toBe('%PDF-1.4 preenchido');
    expect(Envelope::count())->toBe(0);
});

it('a prévia aceita lacunas em branco (mostra o que já foi preenchido)', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    $http = useSealer();

    $this->actingAs($u)->post("/modelos/{$tpl->id}/previa", ['values' => ['nome_do_contratante' => 'Só o nome']])->assertOk();
    $http->assertSent(fn ($r) => str_contains($r->body(), 'Só o nome'));
});

it('obrigatório vazio e valor de 501 caracteres voltam com erro no próprio campo e não criam rascunho', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);

    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody(['values' => ['nome_do_contratante' => '', 'endereco' => 'x', 'observacao' => '']]))->assertSessionHasErrors('values.nome_do_contratante');
    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody(['values' => ['nome_do_contratante' => str_repeat('a', 501), 'endereco' => 'x']]))->assertSessionHasErrors('values.nome_do_contratante');
    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody(['title' => '']))->assertSessionHasErrors('title');
    expect(Envelope::count())->toBe(0);
});

it('valores com HTML, chaves, porcentagem, aspas e emoji seguem como texto simples', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    $http = useSealer();
    $nasty = '<script>alert(1)</script> {{nome}} 100% "aspas" \' 😀 =SOMA(1)';

    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody(['values' => ['nome_do_contratante' => $nasty, 'endereco' => 'x']]))->assertSessionHasNoErrors();

    $http->assertSent(function ($r) use ($nasty) {
        $sent = json_decode(substr($r->body(), strpos($r->body(), '['), strrpos($r->body(), ']') - strpos($r->body(), '[') + 1), true);

        return is_array($sent) && collect($sent)->contains(fn ($f) => $f['text'] === $nasty);
    });
    expect(Envelope::count())->toBe(1);
});

it('texto que não cabe na lacuna volta com a mensagem apontando a lacuna e sem rascunho', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    useSealer(Http::response(['error' => 'does_not_fit', 'field' => 'nome_do_contratante'], 422));

    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody())->assertSessionHasErrors(['values.nome_do_contratante' => "O valor de “Nome do contratante” não cabe na lacuna. Escreva menos texto."]);
    expect(Envelope::count())->toBe(0);

    $this->actingAs($u)->post("/modelos/{$tpl->id}/previa", useBody())->assertStatus(422);
});

it('selador fora do ar avisa sem criar rascunho', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    useSealer(Http::response('boom', 500));

    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody())->assertSessionHasErrors('title');
    expect(Envelope::count())->toBe(0);
});

it('pasta de outra empresa é recusada', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    $other = wzOwner('outro@wz.test');
    $theirs = Folder::create(['tenant_id' => $other->tenant_id, 'name' => 'Alheia']);
    useSealer();

    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody(['folder_id' => $theirs->id]))->assertSessionHasErrors();
    expect(Envelope::count())->toBe(0);
});

it('outra empresa recebe 404 em usar, gerar e prévia', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    $other = wzOwner('outro@wz.test');
    useSealer();
    $this->actingAs($other);

    $this->get("/modelos/{$tpl->id}/usar")->assertNotFound();
    $this->post("/modelos/{$tpl->id}/usar", useBody())->assertNotFound();
    $this->post("/modelos/{$tpl->id}/previa", useBody())->assertNotFound();
    expect(Envelope::count())->toBe(0);
});

it('usar o modelo soma o documento ao armazenamento e o relatório por modelo encontra o documento', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    $before = (int) $u->tenant->fresh()->storage_bytes;
    useSealer();

    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody());

    expect((int) $u->tenant->fresh()->storage_bytes)->toBe($before + strlen('%PDF-1.4 preenchido'));
    $this->actingAs($u)->get('/relatorios?template='.$tpl->id)->assertOk();
    expect(Envelope::where('template_id', $tpl->id)->count())->toBe(1);
});

it('480 caracteres com 30 quebras de linha (que o navegador envia como \r\n) são aceitos', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    $text = implode("\r\n", array_fill(0, 31, str_repeat('a', 15)));  // 31*15 + 30 quebras = 495 com \n; 525 com \r\n
    useSealer();

    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody(['values' => ['nome_do_contratante' => 'x', 'endereco' => $text]]))->assertSessionHasNoErrors();
    expect(Envelope::count())->toBe(1);
});


it('texto com UTF-8 inválido não derruba a geração nem a prévia', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    useSealer();
    $bad = "Jo\xE3o inv\xE1lido";

    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody(['values' => ['nome_do_contratante' => $bad, 'endereco' => 'x']]))->assertSessionHasNoErrors();
    $this->actingAs($u)->post("/modelos/{$tpl->id}/previa", useBody(['values' => ['nome_do_contratante' => $bad]]))->assertOk();
});

it('o Enter nos campos aciona Continuar: o primeiro botão do formulário não é a prévia', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);

    $html = $this->actingAs($u)->get("/modelos/{$tpl->id}/usar")->getContent();
    $firstSubmit = strpos($html, '<button type="submit"');
    $preview = strpos($html, 'formtarget="_blank"');

    expect($firstSubmit)->not->toBeFalse()->and($firstSubmit)->toBeLessThan($preview)->and(substr($html, $firstSubmit, 160))->not->toContain('formaction');
});

it('o rascunho já nasce com o modelo de origem (sem segundo passo que possa falhar)', function () {
    $u = wzOwner();
    $tpl = useTemplate($u);
    useSealer();

    $this->actingAs($u)->post("/modelos/{$tpl->id}/usar", useBody());

    expect(Envelope::firstOrFail()->template_id)->toBe($tpl->id);
});
