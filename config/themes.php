<?php

declare(strict_types=1);

return [
    // Onde os temas instalados ficam (fora de public: os assets saem por rota própria).
    'path' => storage_path('app/themes'),

    // Versão do contrato de variáveis/arquivos que o sistema entende.
    'contract' => 1,

    'max_zip_mb' => 25,
    'max_file_mb' => 8,
    'max_total_mb' => 80,

    // Extensões aceitas dentro do pacote ("blade.php" é tratado à parte).
    'allowed_ext' => ['json', 'css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'txt', 'md'],

    // Extensões que a rota de assets entrega (nunca .php, .json de configuração ou Blade).
    'served_ext' => ['css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot'],
];
