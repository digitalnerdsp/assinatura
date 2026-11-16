<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Themes\ThemeValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

/** Empacota resources/themes-src/<slug> em um .zip pronto para enviar na central e já o confere. */
final class ThemesPack extends Command
{
    protected $signature = 'themes:pack {slug : pasta em resources/themes-src} {--out= : arquivo de saída (padrão storage/app/theme-packages/<slug>.zip)}';

    protected $description = 'Gera o .zip de um tema de origem e confere com as mesmas regras do upload';

    public function handle(): int
    {
        $src = resource_path('themes-src/'.$this->argument('slug'));
        if (! is_dir($src)) {
            $this->error("Pasta não encontrada: {$src}");

            return self::FAILURE;
        }
        $out = (string) ($this->option('out') ?: storage_path('app/theme-packages/'.$this->argument('slug').'.zip'));
        self::pack($src, $out);

        $problems = (new ThemeValidator())->validate($out);
        if ($problems !== []) {
            foreach ($problems as $p) {
                $this->line(" - {$p}");
            }
            $this->error(count($problems).' problema(s) no pacote.');

            return self::FAILURE;
        }
        $this->info('Pacote válido: '.$out.' ('.round(filesize($out) / 1048576, 1).' MB)');

        return self::SUCCESS;
    }

    public static function pack(string $src, string $out): void
    {
        File::ensureDirectoryExists(dirname($out));
        @unlink($out);
        $zip = new ZipArchive();
        $zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $base = rtrim(str_replace('\\', '/', (string) realpath($src)), '/').'/';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), substr(str_replace('\\', '/', $file->getPathname()), strlen($base)));
            }
        }
        $zip->close();
    }
}
