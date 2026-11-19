<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Nenhuma tela usa a caixa de alerta/confirmação do navegador: tudo passa por public/assets/js/assinaja-ui.js. */
it('as telas não chamam alert() nem confirm() do navegador', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.blade.php')) {
            continue;
        }
        $code = (string) file_get_contents((string) $file);
        if (preg_match('/(?<![\w.])(?:window\.)?(?:alert|confirm)\s*\(/', $code)) {
            $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', (string) $file);
        }
    }
    foreach (glob(public_path('assets/js/*.js')) as $js) {
        if (str_contains($js, '.min.') || str_ends_with($js, 'assinaja-ui.js') || str_contains($js, 'jquery') || str_contains($js, 'bootstrap')) {
            continue;
        }
        if (preg_match('/(?<![\w.])(?:window\.)?(?:alert|confirm)\s*\(/', (string) file_get_contents($js))) {
            $offenders[] = basename($js);
        }
    }

    expect($offenders)->toBe([]);
});

it('o script de avisos e confirmações é carregado em todas as telas e a ação perigosa começa em Cancelar', function () {
    $u = wzOwner();
    $this->actingAs($u)->get('/painel')->assertOk()->assertSee('assets/js/assinaja-ui.js', false);
    $this->get('/verificar')->assertOk()->assertSee('assets/js/assinaja-ui.js', false);

    $js = (string) file_get_contents(public_path('assets/js/assinaja-ui.js'));
    expect($js)->toContain('window.ajConfirm')->toContain("(opts.danger ? no : yes).focus()")->toContain("data-confirm");
});

it('formulários que pedem confirmação usam data-confirm, sem onsubmit com confirm', function () {
    $u = wzOwner();
    $e = wzDraft($u);

    $this->actingAs($u)->get("/documentos/{$e->id}")->assertOk()->assertSee('data-confirm="Cancelar este documento?"', false)->assertDontSee('return confirm(', false);
});
