<?php

return [
    // Eventos disponíveis para assinatura pelos clientes.
    'events' => [
        'document.sent' => 'Documento enviado',
        'signer.viewed' => 'Signatário abriu o documento',
        'signer.signed' => 'Signatário assinou',
        'signer.refused' => 'Signatário recusou',
        'document.completed' => 'Documento concluído e selado',
        'document.expired' => 'Documento expirou',
        'document.cancelled' => 'Documento cancelado',
    ],

    // Permite URLs em rede privada/localhost (só para desenvolvimento). Em produção deve ser false (proteção SSRF).
    'allow_private' => (bool) env('WEBHOOKS_ALLOW_PRIVATE', false),
    'timeout' => 10,
    'max_attempts' => 6,
];
