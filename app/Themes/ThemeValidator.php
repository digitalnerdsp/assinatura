<?php

declare(strict_types=1);

namespace App\Themes;

use ZipArchive;

/**
 * Confere um pacote de tema antes de qualquer arquivo ser gravado. Devolve a lista de problemas
 * (vazia = pacote aceito). Blade pode executar PHP, então o tema só usa um conjunto fixo de recursos.
 */
final class ThemeValidator
{
    public const REQUIRED = ['theme.json', 'layouts/app.blade.php', 'pages/dashboard.blade.php'];

    private const FORBIDDEN_FUNCTIONS = 'system|exec|shell_exec|passthru|proc_open|popen|eval|assert|file_get_contents|file_put_contents|fopen|unlink|rmdir|mkdir|chmod|rename|copy|call_user_func|call_user_func_array|base_path|storage_path|app_path|public_path|env|config|app|resolve|auth|request|session|cache|cookie|header|redirect|abort|dd|dump|phpinfo|unserialize|extract';

    /** @return list<string> */
    public function validate(string $zipPath): array
    {
        $problems = [];
        $maxZip = (float) config('themes.max_zip_mb') * 1048576;
        if (! is_file($zipPath)) {
            return ['Arquivo do pacote não encontrado.'];
        }
        if (filesize($zipPath) > $maxZip) {
            return ['Pacote grande demais (máximo '.config('themes.max_zip_mb').' MB).'];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['O arquivo não é um .zip válido.'];
        }

        try {
            $names = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) $stat['name'];
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $names[] = $name;
                $total += (int) $stat['size'];

                if ($this->unsafePath($name)) {
                    $problems[] = "Caminho inseguro: {$name} (\"..\", caminho absoluto ou barra invertida).";
                    continue;
                }
                $ext = $this->extension($name);
                if ($ext === null || ($ext !== 'blade.php' && ! in_array($ext, (array) config('themes.allowed_ext'), true))) {
                    $problems[] = "Tipo de arquivo não permitido: {$name}.";
                    continue;
                }
                if ((int) $stat['size'] > (float) config('themes.max_file_mb') * 1048576) {
                    $problems[] = "Arquivo grande demais: {$name} (máximo ".config('themes.max_file_mb').' MB).';
                    continue;
                }
                $problems = array_merge($problems, $this->scan($name, $ext, (string) $zip->getFromIndex($i)));
            }
            if ($total > (float) config('themes.max_total_mb') * 1048576) {
                $problems[] = 'Conteúdo descompactado grande demais (máximo '.config('themes.max_total_mb').' MB).';
            }
            foreach (self::REQUIRED as $required) {
                if (! in_array($required, $names, true)) {
                    $problems[] = "Arquivo obrigatório ausente: {$required}.";
                }
            }
            if (in_array('theme.json', $names, true)) {
                $problems = array_merge($problems, $this->manifest((string) $zip->getFromName('theme.json')));
            }
        } finally {
            $zip->close();
        }

        return $problems;
    }

    /** @return array<string,mixed>|null manifesto já validado, ou null */
    public function readManifest(string $json): ?array
    {
        return $this->manifest($json) === [] ? (array) json_decode($json, true) : null;
    }

    /** @return list<string> */
    private function manifest(string $json): array
    {
        $m = json_decode($json, true);
        if (! is_array($m)) {
            return ['theme.json: JSON inválido.'];
        }
        $out = [];
        foreach (['name', 'slug', 'version', 'contract'] as $key) {
            if (! isset($m[$key]) || $m[$key] === '') {
                $out[] = "theme.json: falta \"{$key}\".";
            }
        }
        if (isset($m['slug']) && ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $m['slug'])) {
            $out[] = 'theme.json: slug inválido (use letras minúsculas, números e hífen).';
        }
        if (isset($m['version']) && ! preg_match('/^\d+\.\d+\.\d+$/', (string) $m['version'])) {
            $out[] = 'theme.json: versão inválida (use 1.0.0).';
        }
        if (isset($m['contract']) && (int) $m['contract'] !== (int) config('themes.contract')) {
            $out[] = 'theme.json: contrato '.(int) $m['contract'].' não suportado (o sistema entende o contrato '.config('themes.contract').').';
        }

        return $out;
    }

    private function unsafePath(string $name): bool
    {
        return str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('/^[A-Za-z]:/', $name) === 1 || str_contains($name, "\0");
    }

    private function extension(string $name): ?string
    {
        $lower = strtolower($name);
        if (str_ends_with($lower, '.blade.php')) {
            return 'blade.php';
        }
        $ext = pathinfo($lower, PATHINFO_EXTENSION);

        return $ext === '' ? null : $ext;
    }

    /** @return list<string> */
    private function scan(string $name, string $ext, string $content): array
    {
        $out = [];
        if ($ext === 'blade.php') {
            if (preg_match('/@php\b/i', $content)) {
                $out[] = "{$name}: \"@php\" não é permitido.";
            }
            if (str_contains($content, '<?')) {
                $out[] = "{$name}: tag <?php não é permitida.";
            }
            if (str_contains($content, '{!!')) {
                $out[] = "{$name}: \"{!!\" (saída sem escape) não é permitido; use {{ }}.";
            }
            if (preg_match('/@(inject|use|eval|dd|dump)\b/i', $content, $m)) {
                $out[] = "{$name}: diretiva @{$m[1]} não é permitida.";
            }
            if (preg_match_all('/@(include\w*|each|extends|component\w*|livewire)\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $content, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $hit) {
                    if (! str_starts_with($hit[2], 'theme::')) {
                        $out[] = "{$name}: @{$hit[1]} fora do tema ({$hit[2]}); só 'theme::...' é permitido.";
                    }
                }
            }
            if (preg_match('/\b('.self::FORBIDDEN_FUNCTIONS.')\s*\(/i', $content, $m)) {
                $out[] = "{$name}: função proibida: {$m[1]}().";
            }
            if (preg_match('/\\\\?App\\\\/', $content) || preg_match('/\b[A-Z][A-Za-z_]*::[A-Za-z_]/', $content)) {
                $out[] = "{$name}: referência a classe do sistema (\\App\\... ou Classe::método) não é permitida.";
            }
        } elseif ($ext === 'js' && ! str_starts_with($name, 'assets/vendor/')) {
            if (preg_match('/\beval\s*\(/', $content)) {
                $out[] = "{$name}: eval() não é permitido.";
            }
            if (preg_match('/new\s+Function\s*\(/', $content)) {
                $out[] = "{$name}: new Function() não é permitido.";
            }
            if (str_contains($content, 'document.cookie')) {
                $out[] = "{$name}: acesso a document.cookie não é permitido.";
            }
        } elseif ($ext === 'svg') {
            if (preg_match('/<script|\son\w+\s*=|javascript:/i', $content)) {
                $out[] = "{$name}: SVG com script não é permitido.";
            }
        }

        return $out;
    }
}
