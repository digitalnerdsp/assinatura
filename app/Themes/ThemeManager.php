<?php

declare(strict_types=1);

namespace App\Themes;

use App\Envelopes\Exceptions\FlowException;
use App\Models\Setting;
use App\Models\Theme;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Tema ativo da plataforma (global). Sem tema, com tema quebrado ou com a pasta ausente, o sistema usa o
 * layout de reserva ("layouts.legacy") para nunca ficar fora do ar.
 */
final class ThemeManager
{
    public const FALLBACK_LAYOUT = 'layouts.legacy';

    public function active(): ?Theme
    {
        $id = Setting::get('active_theme');
        if ($id === null || $id === '') {
            return null;
        }
        $theme = Theme::query()->find($id);
        if ($theme === null || $theme->status !== 'installed' || ! is_file($theme->directory().'/layouts/app.blade.php')) {
            return null;
        }

        return $theme;
    }

    public function layout(): string
    {
        return $this->active() ? 'theme::layouts.app' : self::FALLBACK_LAYOUT;
    }

    /** View de uma página ("dashboard"): a do tema, se ele tiver; senão a do sistema. */
    public function page(string $name): string
    {
        $theme = $this->active();

        return $theme && is_file($theme->directory()."/pages/{$name}.blade.php") ? "theme::pages.{$name}" : $name;
    }

    /** Aponta o namespace "theme::" para o tema ativo (chamado no boot). */
    public function registerActive(): void
    {
        $theme = $this->active();
        if ($theme !== null) {
            View::replaceNamespace('theme', [$theme->directory()]);
        }
    }

    public function activate(Theme $theme): void
    {
        View::replaceNamespace('theme', [$theme->directory()]);
        try {
            view('theme::pages.dashboard', ThemeData::sample())->render();
        } catch (\Throwable $e) {
            $theme->forceFill(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            Log::warning('Tema recusado na ativação', ['theme' => $theme->slug, 'error' => $e->getMessage()]);
            $this->registerActive();
            throw FlowException::because('theme_broken', 'O tema não abriu e não foi ativado: '.mb_substr($e->getMessage(), 0, 200));
        }

        $theme->forceFill(['status' => 'installed', 'error' => null])->save();
        Setting::put('active_theme', (string) $theme->getKey());
    }

    public function reset(): void
    {
        Setting::query()->whereKey('active_theme')->delete();
    }
}
