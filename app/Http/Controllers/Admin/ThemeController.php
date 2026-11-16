<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Themes\ThemeInstaller;
use App\Themes\ThemeManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Plataforma › Temas (só super admin): enviar, ativar, voltar ao padrão e remover. */
final class ThemeController extends Controller
{
    public function __construct(private readonly ThemeInstaller $installer, private readonly ThemeManager $manager)
    {
    }

    public function index(): View
    {
        return view('admin.themes.index', [
            'themes' => Theme::query()->orderBy('name')->orderByDesc('version')->get(),
            'activeId' => $this->manager->active()?->getKey(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['package' => ['required', 'file', 'mimes:zip', 'max:30720']], [], ['package' => 'pacote']);

        try {
            $theme = $this->installer->install($request->user(), $request->file('package'));
        } catch (FlowException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return redirect()->route('admin.themes.index')->with('status', "Tema {$theme->name} {$theme->version} instalado. Ative quando quiser.");
    }

    public function activate(Theme $theme): RedirectResponse
    {
        try {
            $this->manager->activate($theme);
        } catch (FlowException $e) {
            return back()->withErrors(['theme' => $e->getMessage()]);
        }

        return redirect()->route('admin.themes.index')->with('status', "Tema {$theme->name} ativado para toda a plataforma.");
    }

    public function reset(): RedirectResponse
    {
        $this->manager->reset();

        return redirect()->route('admin.themes.index')->with('status', 'Voltou ao tema padrão do sistema.');
    }

    public function destroy(Request $request, Theme $theme): RedirectResponse
    {
        try {
            $this->installer->remove($request->user(), $theme);
        } catch (FlowException $e) {
            return back()->withErrors(['theme' => $e->getMessage()]);
        }

        return redirect()->route('admin.themes.index')->with('status', 'Tema removido.');
    }
}
