<?php

declare(strict_types=1);

return [
    /*
     | E-mails com acesso ao painel super admin (separados por vírgula em SUPER_ADMIN_EMAILS).
     | ATENÇÃO: não há verificação de e-mail no cadastro. Cadastre a conta ANTES de listar o
     | e-mail aqui (ou feche os cadastros no painel logo depois), para ninguém registrar esse
     | endereço primeiro.
     */
    'super_admins' => array_values(array_filter(array_map(
        fn (string $e) => mb_strtolower(trim($e)),
        explode(',', (string) env('SUPER_ADMIN_EMAILS', '')),
    ))),
];
