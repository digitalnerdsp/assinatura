<?php

declare(strict_types=1);

/**
 * O Dockerfile do selador copia os arquivos .py um a um. Se um módulo novo (como o fill.py) ficar de fora,
 * o contêiner quebra ao iniciar (ModuleNotFoundError), entra em loop e o nome "sealer" some da rede:
 * o painel mostra "Could not resolve host: sealer".
 */
it('o Dockerfile do selador copia todos os módulos locais que o app.py importa', function () {
    $dir = base_path('sealer');
    $local = collect(glob($dir.'/*.py'))->map(fn ($f) => basename($f, '.py'))->all();

    $needed = collect(['app.py'])->flatMap(function (string $file) use ($dir, $local) {
        preg_match_all('/^\s*(?:import|from)\s+([a-zA-Z_][\w]*)/m', (string) file_get_contents("$dir/$file"), $m);

        return array_values(array_intersect(array_unique($m[1]), $local));
    })->all();

    // módulos que os módulos locais importam também precisam ir (ex.: app.py -> report.py)
    $queue = $needed;
    while ($queue) {
        $mod = array_shift($queue);
        preg_match_all('/^\s*(?:import|from)\s+([a-zA-Z_][\w]*)/m', (string) file_get_contents("$dir/$mod.py"), $m);
        foreach (array_intersect(array_unique($m[1]), $local) as $dep) {
            if (! in_array($dep, $needed, true)) {
                $needed[] = $dep;
                $queue[] = $dep;
            }
        }
    }

    $dockerfile = (string) file_get_contents("$dir/Dockerfile");
    preg_match_all('/^COPY\s+(?!requirements)(.+?)\s+\.\/?\s*$/m', $dockerfile, $copies);
    $copied = collect($copies[1])->flatMap(fn ($line) => preg_split('/\s+/', trim($line)))->map(fn ($f) => basename($f, '.py'))->all();

    expect(array_values(array_diff(array_merge(['app'], $needed), $copied)))->toBe([]);
});

it('o app.py do selador importa o fill (a rota /v1/fill depende dele no contêiner)', function () {
    expect(file_get_contents(base_path('sealer/app.py')))->toContain('import fill');
    expect(file_get_contents(base_path('sealer/Dockerfile')))->toContain('fill.py');
});

it('o Dockerfile instala o LibreOffice e as fontes que a conversão de DOCX usa', function () {
    $d = (string) file_get_contents(base_path('sealer/Dockerfile'));
    expect($d)->toContain('libreoffice-writer')->toContain('fonts-liberation')->toContain('convert.py');
    expect(file_get_contents(base_path('sealer/app.py')))->toContain('import convert');
});
