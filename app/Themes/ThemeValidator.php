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
            if ($zip->numFiles > (int) config('themes.max_files')) {
                return ['Pacote com arquivos demais (máximo '.config('themes.max_files').').'];
            }
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
                if (! $this->safeName($name)) {
                    $problems[] = "Nome de arquivo não permitido: {$name} (use só letras, números, ponto, hífen, sublinhado e barra; sem nomes ocultos ou reservados).";
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
        foreach (['name', 'slug', 'version'] as $key) {
            if (! isset($m[$key]) || ! is_string($m[$key]) || trim($m[$key]) === '') {
                $out[] = "theme.json: falta \"{$key}\" (texto).";
            }
        }
        if (! isset($m['contract']) || ! is_int($m['contract'])) {
            $out[] = 'theme.json: falta "contract" (número inteiro).';
        }
        foreach (['name' => 120, 'author' => 120, 'description' => 500] as $key => $max) {
            if (isset($m[$key]) && (! is_string($m[$key]) || mb_strlen($m[$key]) > $max)) {
                $out[] = "theme.json: \"{$key}\" deve ser um texto de até {$max} caracteres.";
            }
        }
        if (isset($m['slug']) && is_string($m['slug']) && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $m['slug']) !== 1) {
            $out[] = 'theme.json: slug inválido (use letras minúsculas, números e hífen).';
        }
        if (isset($m['version']) && is_string($m['version']) && preg_match('/\A\d+\.\d+\.\d+\z/', $m['version']) !== 1) {
            $out[] = 'theme.json: versão inválida (use 1.0.0).';
        }
        if (isset($m['contract']) && is_int($m['contract']) && $m['contract'] !== (int) config('themes.contract')) {
            $out[] = 'theme.json: contrato '.$m['contract'].' não suportado (o sistema entende o contrato '.config('themes.contract').').';
        }

        return $out;
    }

    /** Trechos de PHP do Blade: o que fica dentro de {{ }} e os argumentos de diretivas (@if(...), @foreach(...)). @return list<string> */
    private function expressions(string $content): array
    {
        $content = preg_replace('/\{\{--.*?--\}\}/s', '', $content) ?? $content;
        $out = [];
        if (preg_match_all('/\{\{(.*?)\}\}/s', $content, $m)) {
            $out = $m[1];
        }
        if (preg_match_all('/@\w+[ \t]*\((.*)\)/', $content, $m)) {
            $out = array_merge($out, $m[1]);
        }

        return $out;
    }

    private function safeName(string $name): bool
    {
        if (preg_match('/\A[A-Za-z0-9_][A-Za-z0-9._-]*(?:\/[A-Za-z0-9_][A-Za-z0-9._-]*)*\z/', $name) !== 1) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if (preg_match('/\A(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|\z)/i', $segment) === 1 || str_ends_with($segment, '.')) {
                return false;
            }
        }

        return true;
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
            if (preg_match_all('/@(include\w*|each|extends|component\w*|livewire)\b([^\n]*)/i', $content, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $hit) {
                    if (preg_match('/\A\s*\(\s*[\'"]theme::[A-Za-z0-9_.:-]+[\'"]/', $hit[2]) !== 1) {
                        $out[] = "{$name}: @{$hit[1]} só aceita um nome literal 'theme::...' (nada dinâmico, nada de fora do tema).";
                    }
                }
            }
            if (preg_match('/<(x-|livewire:)/i', $content)) {
                $out[] = "{$name}: componentes <x-...> e <livewire:...> não são permitidos.";
            }
            foreach ($this->expressions($content) as $expr) {
                if (str_contains($expr, '`')) {
                    $out[] = "{$name}: backtick (execução de comando) não é permitido.";
                }
                if (str_contains($expr, '/*')) {
                    $out[] = "{$name}: comentário dentro de expressão não é permitido.";
                }
                if (preg_match('/\$__env|\$this\b|\$GLOBALS|\$_(GET|POST|SERVER|ENV|REQUEST|COOKIE|FILES|SESSION)\b/', $expr)) {
                    $out[] = "{$name}: acesso a \$__env, \$this ou variáveis globais não é permitido.";
                }
                if (preg_match('/\bnew\s/i', $expr)) {
                    $out[] = "{$name}: \"new\" não é permitido.";
                }
                if (preg_match('/\)\s*\(|\]\s*\(|(?<![\w>:\\\\$])\$\w+\s*\(/', $expr)) {
                    $out[] = "{$name}: chamada dinâmica de função (nome em variável ou texto) não é permitida.";
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
        } elseif (in_array($ext, ['css', 'json', 'txt', 'md'], true) && str_contains($content, '<?')) {
            $out[] = "{$name}: tag <? não é permitida.";
        } elseif ($ext === 'svg') {
            if (str_contains($content, '<?php')) {
                $out[] = "{$name}: tag <?php não é permitida.";
            }
            if (preg_match('/<script|\son\w+\s*=|javascript:/i', $content)) {
                $out[] = "{$name}: SVG com script não é permitido.";
            }
        }

        return $out;
    }
}
