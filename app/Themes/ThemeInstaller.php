<?php

declare(strict_types=1);

namespace App\Themes;

use App\Envelopes\Exceptions\FlowException;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

/** Instala e remove temas. O pacote é conferido ANTES de qualquer arquivo ir para a pasta definitiva. */
final class ThemeInstaller
{
    public function __construct(private readonly ThemeValidator $validator)
    {
    }

    public function install(User $by, UploadedFile|string $zip, bool $bundled = false): Theme
    {
        $path = $zip instanceof UploadedFile ? (string) $zip->getRealPath() : $zip;

        $problems = $this->validator->validate($path);
        if ($problems !== []) {
            throw FlowException::because('theme_invalid', implode("\n", $problems));
        }

        $zipFile = new ZipArchive();
        $zipFile->open($path);
        $manifest = (array) $this->validator->readManifest((string) $zipFile->getFromName('theme.json'));
        $slug = (string) $manifest['slug'];
        $version = (string) $manifest['version'];

        if (Theme::query()->where('slug', $slug)->where('version', $version)->exists()) {
            $zipFile->close();
            throw FlowException::because('theme_exists', "O tema {$slug} {$version} já está instalado. Envie uma versão nova.");
        }

        $root = rtrim((string) config('themes.path'), '/\\');
        $quarantine = $root.DIRECTORY_SEPARATOR.'.quarantine'.DIRECTORY_SEPARATOR.Str::random(16);
        $final = $root.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.$version;

        try {
            File::ensureDirectoryExists($quarantine);
            for ($i = 0; $i < $zipFile->numFiles; $i++) {
                $name = (string) $zipFile->getNameIndex($i);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $target = $quarantine.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
                File::ensureDirectoryExists(dirname($target));
                file_put_contents($target, (string) $zipFile->getFromIndex($i));
            }
            $zipFile->close();

            File::ensureDirectoryExists(dirname($final));
            File::moveDirectory($quarantine, $final);

            $theme = Theme::query()->create([
                'slug' => $slug,
                'name' => (string) $manifest['name'],
                'version' => $version,
                'contract' => (int) $manifest['contract'],
                'author' => isset($manifest['author']) ? (string) $manifest['author'] : null,
                'description' => isset($manifest['description']) ? (string) $manifest['description'] : null,
                'status' => 'installed',
                'bundled' => $bundled,
                'installed_by' => $by->exists ? $by->getKey() : null,
            ]);
        } catch (\Throwable $e) {
            File::deleteDirectory($final);
            @$zipFile->close();
            throw $e;
        } finally {
            File::deleteDirectory($quarantine);
        }

        Log::info('Tema instalado', ['theme' => $slug, 'version' => $version, 'by' => $by->getKey()]);

        return $theme;
    }

    public function remove(User $by, Theme $theme): void
    {
        if ($theme->isActive()) {
            throw FlowException::because('theme_active', 'Este é o tema ativo. Ative outro (ou volte ao padrão) antes de remover.');
        }
        if ($theme->bundled) {
            throw FlowException::because('theme_bundled', 'Este tema vem com o sistema e não pode ser removido.');
        }
        File::deleteDirectory($theme->directory());
        $parent = dirname($theme->directory());
        if (is_dir($parent) && count(scandir($parent)) === 2) {
            @rmdir($parent);
        }
        $theme->delete();
        Log::info('Tema removido', ['theme' => $theme->slug, 'version' => $theme->version, 'by' => $by->getKey()]);
    }
}
