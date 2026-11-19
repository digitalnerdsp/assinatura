<?php

declare(strict_types=1);

namespace App\Assistant\Notifications;

/**
 * As situações em que o usuário recebe aviso no WhatsApp. Cada uma tem variáveis em ordem fixa ({{1}}, {{2}}…),
 * um texto padrão em tom de utilidade (sem oferta nem convite, para a Meta manter como "utilidade") e um nome de modelo sugerido.
 */
final class NotificationCatalog
{
    public const MAX_BODY = 1024;

    /** @return array<string,array{label:string,when:string,vars:list<string>,example:list<string>,body:string,meta_name:string}> */
    public static function all(): array
    {
        return [
            'document_completed' => [
                'label' => 'Documento assinado por todos', 'when' => 'Quando o último signatário assina e o documento é concluído.',
                'vars' => ['Título do documento'], 'example' => ['Contrato de prestação de serviços'],
                'body' => 'O documento {{1}} foi assinado por todos. A cópia assinada já está disponível na plataforma.',
                'meta_name' => 'assinaja_documento_assinado',
            ],
            'signer_signed' => [
                'label' => 'Alguém assinou', 'when' => 'A cada signatário que assina o documento que você enviou.',
                'vars' => ['Nome do signatário', 'Título do documento'], 'example' => ['Maria Souza', 'Contrato de prestação de serviços'],
                'body' => 'Olá, {{1}} assinou o documento {{2}}. Acompanhe o andamento na plataforma.',
                'meta_name' => 'assinaja_assinou',
            ],
            'signer_refused' => [
                'label' => 'Alguém recusou', 'when' => 'Quando um signatário recusa assinar o seu documento.',
                'vars' => ['Nome do signatário', 'Título do documento'], 'example' => ['Maria Souza', 'Contrato de prestação de serviços'],
                'body' => 'Atenção, {{1}} recusou a assinatura do documento {{2}}. Veja o motivo na plataforma.',
                'meta_name' => 'assinaja_recusou',
            ],
            'contract_expiring' => [
                'label' => 'Contrato perto de vencer', 'when' => 'Nos dias de aviso definidos no contrato monitorado.',
                'vars' => ['Título do contrato', 'Data de vencimento'], 'example' => ['Contrato de prestação de serviços', '31/12/2026'],
                'body' => 'Lembrete: o contrato {{1}} vence em {{2}}. Confira o prazo e a renovação na plataforma.',
                'meta_name' => 'assinaja_contrato_vence',
            ],
            'document_stalled' => [
                'label' => 'Documento parado', 'when' => 'Quando um documento fica parado, sem nova assinatura, pelo número de dias definido abaixo.',
                'vars' => ['Título do documento', 'Dias parado'], 'example' => ['Contrato de prestação de serviços', '5'],
                'body' => 'Lembrete: o documento {{1}} está parado há {{2}} dias aguardando assinaturas. Acompanhe na plataforma.',
                'meta_name' => 'assinaja_documento_parado',
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** Devolve a mensagem do problema, ou null se o texto serve para a Meta. */
    public static function validateBody(string $key, string $body): ?string
    {
        $def = self::all()[$key] ?? null;
        if ($def === null) {
            return 'Situação desconhecida.';
        }
        $body = trim($body);
        if ($body === '') {
            return 'Escreva o texto do modelo.';
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            return 'O texto pode ter no máximo '.self::MAX_BODY.' caracteres.';
        }

        preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
        $used = array_map('intval', $m[1]);
        $expected = range(1, count($def['vars']));

        foreach ($expected as $n) {
            if (! in_array($n, $used, true)) {
                return 'Use a variável {{'.$n.'}} ('.$def['vars'][$n - 1].') no texto.';
            }
        }
        foreach (array_unique($used) as $n) {
            if (! in_array($n, $expected, true)) {
                return 'A variável {{'.$n.'}} não existe nesta situação (use de {{1}} a {{'.count($expected).'}}).';
            }
        }
        if (preg_match('/^\{\{\d+\}\}/', $body) === 1) {
            return 'A Meta não aceita variável no começo do texto.';
        }
        if (preg_match('/\{\{\d+\}\}[\s.!?,:;]*$/u', $body) === 1) {
            return 'A Meta não aceita variável no fim do texto: termine com uma frase.';
        }

        return null;
    }
}
