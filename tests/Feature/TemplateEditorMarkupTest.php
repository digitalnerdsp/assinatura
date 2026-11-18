<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

it('a edição carrega o visualizador, o editor e o estilo, com ajuda em português e sem falar em HTML', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);

    $this->actingAs($u)->get("/modelos/{$tpl->id}")->assertOk()
        ->assertSee('pdf.min.js', false)->assertSee('assets/js/template-editor.js?v=2', false)->assertSee('assets/css/template-editor.css?v=1', false)
        ->assertSee('id="tpl-viewer"', false)->assertSee('id="tpl-fields-input"', false)->assertSee('id="tpl-list"', false)
        ->assertSee('Clique no documento')->assertDontSee('HTML');
});

it('as lacunas existentes chegam ao editor como JSON legível', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);
    app(\App\Templates\TemplateService::class)->syncFields($tpl, [tplField(['label' => 'Nome "do" contratante', 'multiline' => true])]);

    $html = $this->actingAs($u)->get("/modelos/{$tpl->id}")->getContent();
    preg_match('/data-fields="([^"]*)"/', $html, $m);
    $data = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

    expect($data)->toHaveCount(1)->and($data[0]['label'])->toBe('Nome "do" contratante')->and($data[0]['multiline'])->toBeTrue()->and($data[0]['page'])->toBe(1);
});

it('o editor usa os mesmos limites e contrato do servidor', function () {
    $js = file_get_contents(public_path('assets/js/template-editor.js'));

    expect($js)->toContain('MAX_FIELDS = '.\App\Templates\TemplateService::MAX_FIELDS)->and($js)->toContain('MAX_LABEL = '.\App\Templates\TemplateService::MAX_LABEL)
        ->and($js)->toContain('tpl-fields-input')->and($js)->toContain('textContent');
});

it('trocar o PDF com lacunas mostra a confirmação em português', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);
    app(\App\Templates\TemplateService::class)->syncFields($tpl, [tplField()]);

    $this->actingAs($u)->get("/modelos/{$tpl->id}")->assertOk()->assertSee('Sei que as lacunas serão apagadas')->assertSee('name="confirm"', false);
});

it('os visualizadores de PDF não avaliam código embutido no PDF (pdf.js isEvalSupported desligado)', function () {
    foreach (['template-editor.js', 'wizard-placement.js'] as $file) {
        expect(file_get_contents(public_path("assets/js/{$file}")))->toContain('isEvalSupported: false');
    }
});

it('as lacunas chegam ao editor com a chave, e o editor devolve a chave ao salvar', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);
    app(\App\Templates\TemplateService::class)->syncFields($tpl, [tplField(['label' => 'Valor'])]);

    $html = $this->actingAs($u)->get("/modelos/{$tpl->id}")->getContent();
    preg_match('/data-fields="([^"]*)"/', $html, $m);
    expect(json_decode(html_entity_decode($m[1], ENT_QUOTES), true)[0]['key'])->toBe('valor')
        ->and(file_get_contents(public_path('assets/js/template-editor.js')))->toContain('key: f.key || undefined');
});

it('depois de uma recusa do servidor, o editor recarrega o que o usuário tinha marcado, não o que estava no banco', function () {
    $u = wzOwner();
    $tpl = tplCreate($u->tenant);
    $typed = json_encode([tplField(['label' => 'Digitada agora']), tplField(['label' => 'Inválida', 'page' => 9])]);

    $html = $this->actingAs($u)->from("/modelos/{$tpl->id}")->followingRedirects()->put("/modelos/{$tpl->id}", ['name' => 'X', 'fields' => $typed])->getContent();

    expect($html)->toContain('Digitada agora');
});
