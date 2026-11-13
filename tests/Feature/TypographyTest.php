<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serve a Roboto do próprio servidor e não carrega fontes do Google', function () {
    $this->get('/entrar')
        ->assertOk()
        ->assertDontSee('fonts.googleapis.com', false)
        ->assertSee('assets/css/typography.css', false);
});

it('inclui os arquivos de fonte nos três pesos', function () {
    foreach (['latin', 'latin-ext'] as $subset) {
        foreach ([400, 500, 700] as $weight) {
            expect(file_exists(public_path("assets/fonts/roboto/roboto-{$subset}-{$weight}-normal.woff2")))
                ->toBeTrue("falta roboto-{$subset}-{$weight}-normal.woff2");
        }
    }

    expect(file_get_contents(public_path('assets/css/typography.css')))->toContain('font-weight: 700');
});
