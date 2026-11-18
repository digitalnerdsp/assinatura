<?php

declare(strict_types=1);

use App\Models\DocumentTemplate;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function tplUpload(string $name = 'a.pdf', ?string $content = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content ?? tplPdf());
}

function tplFieldsJson(array $fields): string
{
    return json_encode($fields, JSON_UNESCAPED_UNICODE);
}

it('a lista mostra nome, lacunas e as ações; vazia explica como começar', function () {
    $u = wzOwner();
    $this->actingAs($u)->get('/modelos')->assertOk()->assertSee('Nenhum modelo')->assertSee('PDF');

    $tpl = tplCreate($u->tenant);
    app(\App\Templates\TemplateService::class)->syncFields($tpl, [tplField(), tplField(['label' => 'Valor', 'y' => 0.3])]);

    $this->actingAs($u)->get('/modelos')->assertOk()->assertSee('Contrato de aluguel')->assertSee('2 lacunas')->assertSee(route('templates.edit', $tpl), false)->assertSee(route('templates.use', $tpl), false);
});

it('modelo sem lacunas não oferece o botão Usar e pede para marcar as lacunas', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);

    $this->actingAs($u)->get('/modelos')->assertOk()->assertSee('Marcar lacunas')->assertDontSee(route('templates.use', $tpl), false);
});

it('a tela de novo modelo pede o nome e o PDF, sem falar em HTML', function () {
    $u = wzOwner();

    $this->actingAs($u)->get('/modelos/novo')->assertOk()->assertSee('name="pdf"', false)->assertSee('name="name"', false)->assertDontSee('HTML')->assertDontSee('{{');
});

it('cria o modelo com o PDF e leva à edição', function () {
    $u = wzOwner();
    tplSealer(3);

    $res = $this->actingAs($u)->post('/modelos', ['name' => 'Aluguel', 'pdf' => tplUpload()]);
    $tpl = DocumentTemplate::firstOrFail();

    $res->assertRedirect(route('templates.edit', $tpl))->assertSessionHas('status');
    expect($tpl->name)->toBe('Aluguel')->and($tpl->page_count)->toBe(3)->and($tpl->tenant_id)->toBe($u->tenant_id);
});

it('recusa sem nome, sem PDF, arquivo que não é PDF e PDF inválido, com mensagem em português', function () {
    $u = wzOwner();
    tplSealer();

    $this->actingAs($u)->post('/modelos', ['name' => '', 'pdf' => tplUpload()])->assertSessionHasErrors('name');
    $this->actingAs($u)->post('/modelos', ['name' => 'X'])->assertSessionHasErrors('pdf');
    $this->actingAs($u)->post('/modelos', ['name' => 'X', 'pdf' => UploadedFile::fake()->createWithContent('a.txt', 'texto simples')])->assertSessionHasErrors('pdf');
    tplSealer(error: 'invalid_pdf');
    $this->actingAs($u)->post('/modelos', ['name' => 'X', 'pdf' => tplUpload()])->assertSessionHasErrors(['pdf' => 'Este PDF está protegido por senha ou danificado. Envie outro arquivo.']);
    expect(DocumentTemplate::count())->toBe(0);
});

it('a edição entrega o PDF, as lacunas e a contagem de páginas ao editor', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant, 4);
    app(\App\Templates\TemplateService::class)->syncFields($tpl, [tplField()]);

    $this->actingAs($u)->get("/modelos/{$tpl->id}")->assertOk()->assertSee('id="tpl-viewer"', false)->assertSee('data-pdf-url="'.route('templates.pdf', $tpl).'"', false)
        ->assertSee('data-pages="4"', false)->assertSee('Nome do contratante')->assertDontSee('HTML');
});

it('salva o nome e as lacunas', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);

    $this->actingAs($u)->put("/modelos/{$tpl->id}", ['name' => 'Novo nome', 'fields' => tplFieldsJson([tplField(), tplField(['label' => 'Valor', 'y' => 0.4, 'multiline' => true, 'required' => false])])])
        ->assertRedirect()->assertSessionHas('status', 'Modelo salvo.');

    $tpl->refresh();
    expect($tpl->name)->toBe('Novo nome')->and($tpl->fields()->count())->toBe(2)->and($tpl->fields()->get()->last()->multiline)->toBeTrue();
});

it('lacunas inválidas ou JSON quebrado voltam com erro e não mudam nada', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);
    app(\App\Templates\TemplateService::class)->syncFields($tpl, [tplField(['label' => 'Antiga'])]);

    $this->actingAs($u)->from("/modelos/{$tpl->id}")->put("/modelos/{$tpl->id}", ['name' => 'Outro', 'fields' => tplFieldsJson([tplField(['page' => 9])])])->assertSessionHasErrors('fields');
    $this->actingAs($u)->put("/modelos/{$tpl->id}", ['name' => 'Outro', 'fields' => '{quebrado'])->assertSessionHasErrors('fields');
    $this->actingAs($u)->put("/modelos/{$tpl->id}", ['name' => '', 'fields' => tplFieldsJson([])])->assertSessionHasErrors('name');

    expect($tpl->fresh()->name)->toBe('Contrato de aluguel')->and($tpl->fields()->pluck('label')->all())->toBe(['Antiga']);
});

it('trocar o PDF zera as lacunas, mas só com a confirmação quando já existem lacunas', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);
    app(\App\Templates\TemplateService::class)->syncFields($tpl, [tplField()]);
    tplSealer(6);

    $this->actingAs($u)->post("/modelos/{$tpl->id}/pdf", ['pdf' => tplUpload('novo.pdf', tplPdf(50))])->assertSessionHasErrors('confirm');
    expect($tpl->fields()->count())->toBe(1);

    $this->actingAs($u)->post("/modelos/{$tpl->id}/pdf", ['pdf' => tplUpload('novo.pdf', tplPdf(50)), 'confirm' => '1'])->assertRedirect()->assertSessionHasNoErrors();
    expect($tpl->fresh()->page_count)->toBe(6)->and($tpl->fields()->count())->toBe(0);
});

it('entrega o PDF do modelo só para a empresa dona, com cabeçalhos seguros', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);

    $res = $this->actingAs($u)->get("/modelos/{$tpl->id}/pdf")->assertOk();
    expect($res->headers->get('Content-Type'))->toBe('application/pdf')->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->getContent())->toBe(tplPdf());

    $other = wzOwner('outro@wz.test');
    $this->actingAs($other)->get("/modelos/{$tpl->id}/pdf")->assertNotFound();
});

it('remove o modelo com o arquivo e devolve o espaço', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);
    $path = $tpl->pdf_path;

    $this->actingAs($u)->delete("/modelos/{$tpl->id}")->assertRedirect('/modelos')->assertSessionHas('status', 'Modelo removido.');
    expect(DocumentTemplate::count())->toBe(0)->and((int) $u->tenant->fresh()->storage_bytes)->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('outra empresa recebe 404 em todas as rotas do modelo', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);
    $other = wzOwner('outro@wz.test');
    $this->actingAs($other);

    $this->get("/modelos/{$tpl->id}")->assertNotFound();
    $this->put("/modelos/{$tpl->id}", ['name' => 'X', 'fields' => '[]'])->assertNotFound();
    $this->post("/modelos/{$tpl->id}/pdf", ['pdf' => tplUpload(), 'confirm' => '1'])->assertNotFound();
    $this->delete("/modelos/{$tpl->id}")->assertNotFound();
    expect($tpl->fresh()->name)->toBe('Contrato de aluguel');
});

it('plano sem o recurso de modelos recebe 403 e visitante vai ao login', function () {
    $t = Tenant::create(['name' => 'Sem modelos']);
    $p = Plan::create(['name' => 'P', 'slug' => 'p-tpl', 'active' => true, 'features' => array_fill_keys(Plan::FEATURES, true)]);
    app(PlanService::class)->assign($t, $p)->update(['overrides' => ['features' => ['templates' => false]]]);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'Dono', 'email' => 'sem@wz.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);

    $this->actingAs($u)->get('/modelos')->assertForbidden();
    $this->actingAs($u)->get('/modelos/novo')->assertForbidden();
    auth()->logout();
    $this->get('/modelos')->assertRedirect('/entrar');
});
