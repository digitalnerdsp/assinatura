<?php

declare(strict_types=1);

namespace App\Api;

/** Especificação OpenAPI 3.0 da API v1 (servida em /api/v1/openapi.json e usada por /api/docs). */
final class OpenApiSpec
{
    /** @return array<string,mixed> */
    public static function build(string $baseUrl): array
    {
        $id = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Id do recurso (UUID). Em envelopes também aceita o seu external_id.'];
        $uuid = fn (string $name, string $desc) => ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid'], 'description' => $desc];
        $ref = fn (string $name) => ['$ref' => '#/components/schemas/'.$name];
        $json = fn (array $schema, string $desc = 'OK') => ['description' => $desc, 'content' => ['application/json' => ['schema' => $schema]]];
        $err = fn (string $desc) => ['description' => $desc, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        $body = fn (array $schema) => ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
        $list = fn (string $schema) => $json(['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$schema]]]]);
        $common = ['401' => $err('Chave ausente, inválida ou revogada'), '403' => $err('Chave somente leitura, conta suspensa, recurso fora do plano (feature_off) ou limite do plano (plan_limit_reached, com reason)'), '429' => $err('Limite de 120 requisições por minuto')];
        $idem = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 120],
            'description' => 'Repetir o POST com a mesma chave (e o mesmo conteúdo) devolve a resposta original, com o cabeçalho Idempotent-Replayed: true, em vez de criar outro. Vale por 24 h.'];

        $signersInput = ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['type' => 'object', 'required' => ['name', 'email'], 'properties' => [
            'name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email'],
            'phone' => ['type' => 'string', 'description' => 'Para WhatsApp/SMS. Aceita (11) 99999-8888 ou +5511999998888.'],
            'cpf' => ['type' => 'string'],
            'role' => ['type' => 'string', 'enum' => ['signer', 'witness'], 'default' => 'signer', 'description' => 'witness = testemunha: assina depois dos signatários e é exigida para concluir. Pelo menos um item precisa ser signer.'],
            'position' => ['type' => 'integer', 'description' => 'Ordem (ordem sequencial). Mesma posição = assinam juntos.'],
        ]]];
        $options = [
            'external_id' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Seu identificador (único por conta). Pode ser usado no lugar do id nas rotas.'],
            'metadata' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Dados livres; voltam nas respostas e nos webhooks.'],
            'message' => ['type' => 'string'],
            'signing_order' => ['type' => 'string', 'enum' => ['parallel', 'sequential'], 'default' => 'parallel'],
            'require_otp' => ['type' => 'boolean'], 'require_selfie' => ['type' => 'boolean'],
            'require_document' => ['type' => 'boolean'], 'require_geolocation' => ['type' => 'boolean'],
            'validity_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30],
            'reminder_every_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 30, 'description' => '0 desliga os lembretes.'],
            'channels' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['email', 'whatsapp', 'sms']], 'description' => 'Por onde avisar. Padrão: email.'],
            'folder_id' => ['type' => 'string', 'format' => 'uuid'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
            'send' => ['type' => 'boolean', 'description' => 'Envia os convites logo após criar.'],
        ];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name', 'Assinaturas').' — API v1',
                'version' => '1.0.0',
                'description' => "API REST para criar e acompanhar envelopes de assinatura eletrônica.\n\n"
                    ."**Autenticação:** `Authorization: Bearer sk_live_...` (gere a chave em *API* no painel). Chaves *somente leitura* aceitam apenas GET.\n\n"
                    ."**Erros:** sempre `{\"error\": \"codigo\", \"message\": \"texto\"}`; validação traz também `errors` por campo (HTTP 422).\n\n"
                    ."**Limites:** 120 requisições por minuto por chave; PDF de até ".(int) config('signing.max_pdf_mb')." MB.\n\n"
                    .'**Webhooks:** veja `/webhooks` no painel ou `POST /webhooks`. Cada chamada leva `X-Webhook-Signature: sha256=HMAC_SHA256(timestamp + "." + corpo, segredo)`.',
            ],
            'servers' => [['url' => rtrim($baseUrl, '/').'/api/v1']],
            'security' => [['bearerAuth' => []]],
            'tags' => [['name' => 'Conta'], ['name' => 'Envelopes'], ['name' => 'Signatários'], ['name' => 'Modelos'], ['name' => 'Webhooks'], ['name' => 'Pastas e etiquetas'], ['name' => 'Verificação']],
            'paths' => [
                '/me' => ['get' => ['tags' => ['Conta'], 'summary' => 'Confere a chave e mostra a conta e o consumo', 'responses' => ['200' => $json($ref('Me'))] + $common]],

                '/envelopes' => [
                    'get' => ['tags' => ['Envelopes'], 'summary' => 'Lista envelopes (paginado)', 'parameters' => [
                        ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['draft', 'sent', 'in_progress', 'sealing', 'completed', 'refused', 'expired', 'cancelled']]],
                        ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Busca em título, código, external_id, nome e e-mail dos signatários.'],
                        ['name' => 'external_id', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'folder_id', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'UUID da pasta ou "none".'],
                        ['name' => 'tag_id', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'uuid']],
                        ['name' => 'created_from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                        ['name' => 'created_to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date-time']],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]],
                    ], 'responses' => ['200' => $json(['type' => 'object', 'properties' => [
                        'data' => ['type' => 'array', 'items' => $ref('Envelope')],
                        'meta' => ['type' => 'object', 'properties' => ['current_page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'last_page' => ['type' => 'integer']]],
                    ]])] + $common],
                    'post' => ['tags' => ['Envelopes'], 'summary' => 'Cria um envelope (e opcionalmente já envia)',
                        'description' => 'Envie o PDF em **multipart** (campo `document`) ou em **JSON** com `document_base64`. Em multipart use a notação `signers[0][name]`.',
                        'parameters' => [$idem],
                        'requestBody' => ['required' => true, 'content' => [
                            'application/json' => ['schema' => ['type' => 'object', 'required' => ['title', 'document_base64', 'signers'], 'properties' => [
                                'title' => ['type' => 'string'],
                                'document_base64' => ['type' => 'string', 'format' => 'byte', 'description' => 'PDF em base64.'],
                                'document_name' => ['type' => 'string', 'example' => 'contrato.pdf'],
                                'signers' => $signersInput,
                                'sender_signs' => ['type' => 'boolean', 'description' => 'A empresa (remetente) assina junto? Padrão segue a política da empresa. Exige chave com permissão de assinar pela empresa, representante cadastrado e termo de delegação aceito; senão 422 com sender_signature_not_allowed ou no_company_representative (nada é criado). A assinatura é aplicada no envio.'],
                                'signature_fields' => ['type' => 'array', 'description' => 'Onde cada assinatura aparece no PDF. x/y em frações da página (0 a 1), origem no canto superior esquerdo.', 'items' => ['type' => 'object', 'required' => ['signer', 'page', 'x', 'y'], 'properties' => [
                                    'signer' => ['description' => 'Índice do signatário na lista (começa em 0) ou "sender" para a assinatura da empresa.', 'oneOf' => [['type' => 'integer'], ['type' => 'string', 'enum' => ['sender']]]], 'page' => ['type' => 'integer', 'minimum' => 1],
                                    'x' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'y' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]]]],
                            ] + $options]],
                            'multipart/form-data' => ['schema' => ['type' => 'object', 'required' => ['title', 'document'], 'properties' => ['title' => ['type' => 'string'], 'document' => ['type' => 'string', 'format' => 'binary']] + $options]],
                        ]],
                        'responses' => ['201' => $json($ref('Envelope'), 'Criado'), '422' => $err('Dados inválidos, PDF inválido/grande demais ou external_id repetido')] + $common],
                ],
                '/envelopes/{id}' => ['get' => ['tags' => ['Envelopes'], 'summary' => 'Consulta um envelope', 'parameters' => [$id], 'responses' => ['200' => $json($ref('Envelope')), '404' => $err('Não encontrado')] + $common]],
                '/envelopes/{id}/send' => ['post' => ['tags' => ['Envelopes'], 'summary' => 'Libera o rascunho para assinatura e avisa os signatários', 'parameters' => [$id], 'responses' => ['200' => $json($ref('Envelope')), '409' => $err('Não é rascunho, conta suspensa ou limite do plano')] + $common]],
                '/envelopes/{id}/cancel' => ['post' => ['tags' => ['Envelopes'], 'summary' => 'Cancela um envelope em aberto', 'parameters' => [$id], 'responses' => ['200' => $json($ref('Envelope')), '409' => $err('Não pode ser cancelado')] + $common]],
                '/envelopes/{id}/reseal' => ['post' => ['tags' => ['Envelopes'], 'summary' => 'Tenta selar de novo um envelope parado em "sealing"', 'parameters' => [$id], 'responses' => ['202' => $json($ref('Envelope'), 'Reenfileirado'), '409' => $err('Não está aguardando selagem')] + $common]],
                '/envelopes/{id}/evidence' => ['get' => ['tags' => ['Envelopes'], 'summary' => 'Trilha de auditoria (cadeia de hash)', 'parameters' => [$id], 'responses' => ['200' => $json(['type' => 'object', 'properties' => ['chain_intact' => ['type' => 'boolean'], 'events' => ['type' => 'array', 'items' => ['type' => 'object']]]])] + $common]],
                '/envelopes/{id}/download' => ['get' => ['tags' => ['Envelopes'], 'summary' => 'Baixa o PDF selado (com relatório de assinaturas)', 'parameters' => [$id], 'responses' => ['200' => ['description' => 'PDF', 'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]], '409' => $err('Ainda não foi selado')] + $common]],
                '/envelopes/{id}/original' => ['get' => ['tags' => ['Envelopes'], 'summary' => 'Baixa o PDF original enviado', 'parameters' => [$id], 'responses' => ['200' => ['description' => 'PDF', 'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]]] + $common]],

                '/envelopes/{id}/signers/{signer}/resend' => ['post' => ['tags' => ['Signatários'], 'summary' => 'Reenvia o aviso a quem está na vez de assinar', 'parameters' => [$id, $uuid('signer', 'Id do signatário')], 'responses' => ['200' => $json(['type' => 'object', 'properties' => ['sent' => ['type' => 'boolean']]]), '409' => $err('Sem link ativo ou fora da vez'), '502' => $err('Canal indisponível')] + $common]],
                '/envelopes/{id}/signers/{signer}/new-link' => ['post' => ['tags' => ['Signatários'], 'summary' => 'Gera um link novo (o anterior para de funcionar)', 'parameters' => [$id, $uuid('signer', 'Id do signatário')], 'responses' => ['200' => $json($ref('Signer')), '409' => $err('Envelope fechado ou signatário já respondeu')] + $common]],

                '/templates' => [
                    'get' => ['tags' => ['Modelos'], 'summary' => 'Lista modelos', 'responses' => ['200' => $list('Template')] + $common],
                    'post' => ['tags' => ['Modelos'], 'summary' => 'Cria um modelo', 'requestBody' => $body(['type' => 'object', 'required' => ['name', 'body'], 'properties' => ['name' => ['type' => 'string'], 'body' => ['type' => 'string', 'description' => 'HTML simples com {{variavel}}.']]]), 'responses' => ['201' => $json($ref('Template'))] + $common],
                ],
                '/templates/{id}' => [
                    'get' => ['tags' => ['Modelos'], 'summary' => 'Consulta um modelo', 'parameters' => [$id], 'responses' => ['200' => $json($ref('Template'))] + $common],
                    'put' => ['tags' => ['Modelos'], 'summary' => 'Atualiza um modelo', 'parameters' => [$id], 'requestBody' => $body(['type' => 'object', 'required' => ['name', 'body'], 'properties' => ['name' => ['type' => 'string'], 'body' => ['type' => 'string']]]), 'responses' => ['200' => $json($ref('Template'))] + $common],
                    'delete' => ['tags' => ['Modelos'], 'summary' => 'Remove um modelo', 'parameters' => [$id], 'responses' => ['204' => ['description' => 'Removido']] + $common],
                ],
                '/templates/{id}/envelopes' => ['post' => ['tags' => ['Modelos'], 'summary' => 'Gera um envelope a partir do modelo', 'parameters' => [$id, $idem],
                    'requestBody' => $body(['type' => 'object', 'required' => ['title', 'signers'], 'properties' => ['title' => ['type' => 'string'], 'variables' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'example' => ['contratante' => 'Maria Silva', 'valor' => '1.500,00']], 'signers' => $signersInput] + $options]),
                    'responses' => ['201' => $json($ref('Envelope')), '422' => $err('Variável faltando ou dados inválidos')] + $common]],

                '/webhooks' => [
                    'get' => ['tags' => ['Webhooks'], 'summary' => 'Lista webhooks', 'responses' => ['200' => $list('Webhook')] + $common],
                    'post' => ['tags' => ['Webhooks'], 'summary' => 'Cria um webhook (o segredo é devolvido só agora)', 'requestBody' => $body(['type' => 'object', 'required' => ['url'], 'properties' => ['url' => ['type' => 'string', 'format' => 'uri', 'description' => 'https público.'], 'events' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(config('webhooks.events'))], 'description' => 'Vazio = todos.']]]), 'responses' => ['201' => $json($ref('Webhook')), '422' => $err('URL inválida ou interna')] + $common],
                ],
                '/webhooks/{id}' => [
                    'patch' => ['tags' => ['Webhooks'], 'summary' => 'Ativa ou desativa', 'parameters' => [$id], 'requestBody' => $body(['type' => 'object', 'required' => ['active'], 'properties' => ['active' => ['type' => 'boolean']]]), 'responses' => ['200' => $json($ref('Webhook'))] + $common],
                    'delete' => ['tags' => ['Webhooks'], 'summary' => 'Remove', 'parameters' => [$id], 'responses' => ['204' => ['description' => 'Removido']] + $common],
                ],
                '/webhooks/{id}/test' => ['post' => ['tags' => ['Webhooks'], 'summary' => 'Envia um evento "ping"', 'parameters' => [$id], 'responses' => ['202' => $json(['type' => 'object', 'properties' => ['delivery_id' => ['type' => 'string']]])] + $common]],
                '/webhooks/{id}/deliveries' => ['get' => ['tags' => ['Webhooks'], 'summary' => 'Últimas 50 entregas', 'parameters' => [$id], 'responses' => ['200' => $json(['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object']]]])] + $common]],

                '/folders' => [
                    'get' => ['tags' => ['Pastas e etiquetas'], 'summary' => 'Lista pastas', 'responses' => ['200' => $list('Folder')] + $common],
                    'post' => ['tags' => ['Pastas e etiquetas'], 'summary' => 'Cria pasta', 'description' => 'Até 4 níveis (primária + 3 subníveis). Nome único dentro da mesma pasta pai.', 'requestBody' => $body(['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'parent_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true, 'description' => 'Pasta pai (vazio = raiz).']]]), 'responses' => ['201' => $json($ref('Folder')), '404' => ['description' => 'parent_id inexistente ou de outra conta'], '422' => $err('Nome repetido (validation_failed), folder_too_deep')] + $common],
                ],
                '/folders/{id}' => [
                    'patch' => ['tags' => ['Pastas e etiquetas'], 'summary' => 'Renomeia e/ou move a pasta', 'parameters' => [$id], 'requestBody' => $body(['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'parent_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true, 'description' => 'null = raiz.']]]), 'responses' => ['200' => $json($ref('Folder')), '422' => $err('folder_cycle, folder_too_deep ou nome repetido')] + $common],
                    'delete' => ['tags' => ['Pastas e etiquetas'], 'summary' => 'Apaga a pasta e as subpastas', 'description' => 'Rascunhos, cancelados e expirados vão para a lixeira de 30 dias; em andamento, concluídos, recusados e arquivados ficam e passam para a pasta pai da pasta apagada (raiz se for primária).', 'parameters' => [$id], 'responses' => ['200' => $json(['type' => 'object', 'properties' => ['trashed' => ['type' => 'integer'], 'kept' => ['type' => 'integer'], 'folders' => ['type' => 'integer', 'description' => 'Subpastas apagadas junto.']]])] + $common],
                ],
                '/contacts' => [
                    'get' => ['tags' => ['Contatos'], 'summary' => 'Lista contatos', 'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Busca por nome, e-mail ou empresa.'], ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]], 'responses' => ['200' => $list('Contact')] + $common],
                    'post' => ['tags' => ['Contatos'], 'summary' => 'Cria contato', 'description' => 'E-mail único por conta (sem diferenciar maiúsculas); telefone vira E.164; CPF/CNPJ só dígitos.', 'requestBody' => $body(['type' => 'object', 'required' => ['name', 'email'], 'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email'], 'phone' => ['type' => 'string'], 'document' => ['type' => 'string'], 'company' => ['type' => 'string'], 'notes' => ['type' => 'string']]]), 'responses' => ['201' => $json($ref('Contact')), '422' => $err('contact_email_taken, invalid_phone, invalid_document ou dados inválidos')] + $common],
                ],
                '/contacts/{id}' => [
                    'get' => ['tags' => ['Contatos'], 'summary' => 'Mostra contato', 'parameters' => [$id], 'responses' => ['200' => $json($ref('Contact')), '404' => ['description' => 'Inexistente ou de outra conta']] + $common],
                    'patch' => ['tags' => ['Contatos'], 'summary' => 'Altera contato (só os campos enviados)', 'parameters' => [$id], 'requestBody' => $body(['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email'], 'phone' => ['type' => 'string', 'nullable' => true], 'document' => ['type' => 'string', 'nullable' => true], 'company' => ['type' => 'string', 'nullable' => true], 'notes' => ['type' => 'string', 'nullable' => true]]]), 'responses' => ['200' => $json($ref('Contact')), '404' => ['description' => 'Inexistente ou de outra conta'], '422' => $err('contact_email_taken, invalid_phone ou invalid_document')] + $common],
                    'delete' => ['tags' => ['Contatos'], 'summary' => 'Apaga contato (documentos já enviados não mudam)', 'parameters' => [$id], 'responses' => ['204' => ['description' => 'Apagado'], '404' => ['description' => 'Inexistente ou de outra conta']] + $common],
                ],
                '/tags' => [
                    'get' => ['tags' => ['Pastas e etiquetas'], 'summary' => 'Lista etiquetas', 'responses' => ['200' => $list('Tag')] + $common],
                    'post' => ['tags' => ['Pastas e etiquetas'], 'summary' => 'Cria etiqueta', 'requestBody' => $body(['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'color' => ['type' => 'string', 'enum' => \App\Models\Tag::COLORS]]]), 'responses' => ['201' => $json($ref('Tag'))] + $common],
                ],
                '/tags/{id}' => ['patch' => ['tags' => ['Pastas e etiquetas'], 'summary' => 'Renomeia e/ou troca a cor', 'parameters' => [$id], 'requestBody' => $body(['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'color' => ['type' => 'string', 'enum' => \App\Models\Tag::COLORS]]]), 'responses' => ['200' => $json($ref('Tag'))] + $common], 'delete' => ['tags' => ['Pastas e etiquetas'], 'summary' => 'Remove etiqueta', 'parameters' => [$id], 'responses' => ['204' => ['description' => 'Removida']] + $common]],

                '/verify/{code}' => ['get' => ['tags' => ['Verificação'], 'summary' => 'Verificação pública por código (sem chave)', 'security' => [],
                    'parameters' => [['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], ['name' => 'sha256', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Hash de um arquivo para conferir se é o original ou o selado.']],
                    'responses' => ['200' => $json($ref('Verification')), '404' => $err('Código não encontrado')]]],
            ],
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Chave de API: sk_live_...']],
                'schemas' => [
                    'Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'string', 'example' => 'validation_failed'], 'message' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
                    'Me' => ['type' => 'object', 'properties' => ['api_version' => ['type' => 'string'], 'tenant' => ['type' => 'object'], 'key' => ['type' => 'object'], 'plan' => ['type' => 'object', 'description' => 'name, status (trialing|active|blocked|canceled), trial_ends_at, period_end'], 'usage' => ['type' => 'object', 'description' => 'documents {used,limit,credits}, users {used,limit}, storage {used_bytes,limit_bytes}; limit nulo = ilimitado'], 'webhook_events' => ['type' => 'array', 'items' => ['type' => 'string']]]],
                    'Signer' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'], 'name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'phone' => ['type' => 'string', 'nullable' => true],
                        'role' => ['type' => 'string', 'enum' => ['signer', 'witness']], 'is_sender' => ['type' => 'boolean', 'description' => 'true para quem enviou (a empresa).'], 'signed_via' => ['type' => 'string', 'nullable' => true, 'enum' => ['link', 'session', 'api_key']], 'position' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['pending', 'viewed', 'signed', 'refused']],
                        'viewed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true], 'signed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        'refused_reason' => ['type' => 'string', 'nullable' => true], 'reminders_count' => ['type' => 'integer'],
                        'sign_url' => ['type' => 'string', 'nullable' => true, 'description' => 'Link pessoal de assinatura (só enquanto o envelope está aberto). Trate como senha.'],
                    ]],
                    'Envelope' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'], 'external_id' => ['type' => 'string', 'nullable' => true], 'metadata' => ['type' => 'object', 'nullable' => true],
                        'title' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['draft', 'sent', 'in_progress', 'sealing', 'completed', 'refused', 'expired', 'cancelled']],
                        'signing_order' => ['type' => 'string'], 'required_level' => ['type' => 'string', 'enum' => ['simple', 'advanced']],
                        'verifications' => ['type' => 'object'], 'channels' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'validity_days' => ['type' => 'integer'], 'reminder_every_days' => ['type' => 'integer', 'nullable' => true],
                        'folder' => ['type' => 'object', 'nullable' => true], 'tags' => ['type' => 'array', 'items' => $ref('Tag')],
                        'document' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'size' => ['type' => 'integer'], 'sha256' => ['type' => 'string']]],
                        'verification_code' => ['type' => 'string'], 'verification_url' => ['type' => 'string'],
                        'created_at' => ['type' => 'string', 'format' => 'date-time'], 'sent_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true], 'completed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        'final_sha256' => ['type' => 'string', 'nullable' => true], 'signers' => ['type' => 'array', 'items' => $ref('Signer')],
                    ]],
                    'Template' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'variables' => ['type' => 'array', 'items' => ['type' => 'string']], 'body' => ['type' => 'string'], 'updated_at' => ['type' => 'string', 'format' => 'date-time']]],
                    'Webhook' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'url' => ['type' => 'string'], 'events' => ['type' => 'array', 'nullable' => true, 'items' => ['type' => 'string']], 'active' => ['type' => 'boolean'], 'secret' => ['type' => 'string', 'description' => 'Só na criação.'], 'created_at' => ['type' => 'string', 'format' => 'date-time']]],
                    'Folder' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'parent_id' => ['type' => 'string', 'nullable' => true], 'path' => ['type' => 'string', 'description' => 'Caminho, ex.: Contratos / 2026.']]],
                    'Contact' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'phone' => ['type' => 'string', 'nullable' => true, 'description' => 'E.164'], 'document' => ['type' => 'string', 'nullable' => true], 'company' => ['type' => 'string', 'nullable' => true], 'notes' => ['type' => 'string', 'nullable' => true], 'created_at' => ['type' => 'string', 'format' => 'date-time']]],
                    'Tag' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'color' => ['type' => 'string']]],
                    'Verification' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'status' => ['type' => 'string'], 'title' => ['type' => 'string'], 'issuer' => ['type' => 'string'], 'completed_at' => ['type' => 'string', 'nullable' => true], 'document_sha256' => ['type' => 'string'], 'final_sha256' => ['type' => 'string', 'nullable' => true], 'chain_intact' => ['type' => 'boolean'], 'signers' => ['type' => 'array', 'items' => ['type' => 'object']], 'match' => ['type' => 'string', 'enum' => ['final', 'original', 'none']]]],
                ],
            ],
        ];
    }
}
