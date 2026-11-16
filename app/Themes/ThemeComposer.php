<?php

declare(strict_types=1);

namespace App\Themes;

use App\Branding\BrandService;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

/**
 * Entrega ao layout do tema as variáveis do contrato v1:
 * $themeAssets (URL base de assets/ do tema, sem barra final), $brand, $user, $isAdmin, $isSuper, $menu, $flash (avisos e faixa do plano, HTML Bootstrap pronto: o layout deve pô-lo dentro de .legacy-scope),
 * $legacyCss e $legacyScripts (telas internas ainda em Bootstrap).
 */
final class ThemeComposer
{
    public function compose(View $view): void
    {
        if (! view()->shared('errors')) {
            view()->share('errors', new \Illuminate\Support\ViewErrorBag());
        }
        $user = auth()->user();
        $brand = BrandService::forRequest(request());

        $current = app(ThemeManager::class)->current();

        $view->with([
            'themeAssets' => $current ? url("/temas/{$current->slug}/{$current->version}/assets") : '',
            'brand' => $brand,
            'user' => $user,
            'isAdmin' => (bool) $user?->isPlatformAdmin(),
            'isSuper' => (bool) $user?->isSuperAdmin(),
            'menu' => $user ? app(MenuBuilder::class)->for($user) : [],
            'flash' => new HtmlString(view('layouts.partials.flash')->render().($user ? view('layouts.partials.plan-banner')->render() : '')),
            'legacyCss' => [asset('assets/css/icons.css'), asset('assets/css/legacy-scope.css').'?v=1'],
            'legacyScripts' => new HtmlString(view('layouts.partials.scripts')->render()),
        ]);
    }
}
