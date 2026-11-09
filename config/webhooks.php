<?php

return [
    // Eventos disponíveis para assinatura pelos clientes.
    'events' => [
        'envelope.sent' => 'Envelope enviado',
        'signer.viewed' => 'Signatário abriu o documento',
        'signer.signed' => 'Signatário assinou',
        'signer.refused' => 'Signatário recusou',
        'envelope.completed' => 'Envelope concluído e selado',
        'envelope.expired' => 'Envelope expirou',
        'envelope.cancelled' => 'Envelope cancelado',
    ],

    // Permite URLs em rede privada/localhost (só para desenvolvimento). Em produção deve ser false (proteção SSRF).
    'allow_private' => (bool) env('WEBHOOKS_ALLOW_PRIVATE', false),
    'timeout' => 10,
    'max_attempts' => 6,
];
