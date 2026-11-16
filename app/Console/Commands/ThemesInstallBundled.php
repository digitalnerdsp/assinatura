<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Theme;
use App\Models\User;
use App\Themes\ThemeInstaller;
use App\Themes\ThemeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Instala os temas que vêm com o sistema (resources/themes-src) e, na primeira vez, ativa o padrão. Seguro rodar a cada deploy. */
final class ThemesInstallBundled extends Command
{
    protected $signature = 'themes:install-bundled';

    protected $description = 'Instala os temas do sistema e ativa o padrão se nenhum estiver ativo';

    public function handle(ThemeInstaller $installer, ThemeManager $manager): int
    {
        foreach (glob(resource_path('themes-src/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $manifest = json_decode((string) @file_get_contents($dir.'/theme.json'), true);
            if (! is_array($manifest) || Theme::query()->where('slug', $manifest['slug'] ?? '')->where('version', $manifest['version'] ?? '')->exists()) {
                continue;
            }
            $zip = storage_path('app/theme-packages/bundled-'.basename($dir).'.zip');
            ThemesPack::pack($dir, $zip);
            try {
                $theme = $installer->install(new User(), $zip, bundled: true);
                $this->info("Instalado: {$theme->name} {$theme->version}");
            } catch (\Throwable $e) {
                $this->error(basename($dir).': '.$e->getMessage());
            } finally {
                File::delete($zip);
            }
        }

        $default = (string) config('themes.default');
        if (Setting::get('active_theme') === null && $default !== '') {
            $theme = Theme::query()->where('slug', $default)->orderByDesc('version')->first();
            if ($theme !== null) {
                try {
                    $manager->activate($theme);
                    $this->info("Tema padrão ativado: {$theme->name}");
                } catch (\Throwable $e) {
                    $this->warn('Tema padrão não ativado: '.$e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }
}
