<?php

declare(strict_types=1);

namespace App\Assistant\Channel;

use App\Assistant\AssistantSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/** Cliente da API de Nuvem do WhatsApp (Meta). Credenciais vêm da configuração da plataforma; falhas viram MetaException. */
final class MetaWhatsAppClient
{
    private const MAX_TEXT = 4096;
    private const MAX_MEDIA_BYTES = 25 * 1024 * 1024;
    /** A Meta serve mídia destes domínios; qualquer outro endereço devolvido é recusado. */
    private const MEDIA_HOSTS = ['.fbsbx.com', '.fbcdn.net', '.facebook.com', '.whatsapp.net', '.whatsapp.com'];

    public function __construct(private readonly AssistantSettings $settings)
    {
    }

    public function sendText(string $to, string $text): string
    {
        return $this->send(['to' => $to, 'type' => 'text', 'text' => ['body' => mb_substr($text, 0, self::MAX_TEXT), 'preview_url' => false]]);
    }

    /** @param list<array{id:string,title:string}> $buttons até 3, título até 20 caracteres */
    public function sendButtons(string $to, string $body, array $buttons): string
    {
        if ($buttons === [] || count($buttons) > 3) {
            throw new InvalidArgumentException('Botões de resposta: de 1 a 3.');
        }
        $out = [];
        foreach ($buttons as $b) {
            if (mb_strlen((string) $b['title']) > 20 || (string) $b['title'] === '' || (string) $b['id'] === '') {
                throw new InvalidArgumentException('O título do botão precisa ter de 1 a 20 caracteres.');
            }
            $out[] = ['type' => 'reply', 'reply' => ['id' => (string) $b['id'], 'title' => (string) $b['title']]];
        }

        return $this->send(['to' => $to, 'type' => 'interactive', 'interactive' => ['type' => 'button', 'body' => ['text' => mb_substr($body, 0, 1024)], 'action' => ['buttons' => $out]]]);
    }

    /** @param list<array{id:string,title:string,description?:string}> $rows até 10 */
    public function sendList(string $to, string $body, string $button, array $rows): string
    {
        if ($rows === [] || count($rows) > 10) {
            throw new InvalidArgumentException('Lista: de 1 a 10 itens.');
        }
        $items = [];
        foreach ($rows as $r) {
            $item = ['id' => (string) $r['id'], 'title' => mb_substr((string) $r['title'], 0, 24)];
            if (! empty($r['description'])) {
                $item['description'] = mb_substr((string) $r['description'], 0, 72);
            }
            $items[] = $item;
        }

        return $this->send(['to' => $to, 'type' => 'interactive', 'interactive' => ['type' => 'list', 'body' => ['text' => mb_substr($body, 0, 1024)], 'action' => ['button' => mb_substr($button, 0, 20), 'sections' => [['rows' => $items]]]]]);
    }

    public function markRead(string $waMessageId): void
    {
        $this->call('post', $this->phoneUrl('messages'), ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $waMessageId]);
    }

    /** @return array{bytes:string,mime:string} */
    public function downloadMedia(string $mediaId): array
    {
        $info = $this->call('get', $this->base().'/'.rawurlencode($mediaId));
        $url = (string) ($info['url'] ?? '');
        $this->assertMediaUrl($url);

        try {
            $response = $this->request()->timeout(30)->get($url);
        } catch (ConnectionException $e) {
            throw new MetaException('Falha de rede ao baixar o arquivo da Meta.', 0, null, $e);
        }
        $this->throwIfFailed($response);
        $bytes = $response->body();
        if (strlen($bytes) > self::MAX_MEDIA_BYTES) {
            throw new MetaException('Arquivo grande demais para baixar.');
        }

        return ['bytes' => $bytes, 'mime' => (string) ($info['mime_type'] ?? 'application/octet-stream')];
    }

    public function uploadMedia(string $bytes, string $mime): string
    {
        $this->ensureConfigured();
        try {
            $response = $this->request()->timeout(60)->attach('file', $bytes, 'document', ['Content-Type' => $mime])
                ->post($this->phoneUrl('media'), ['messaging_product' => 'whatsapp', 'type' => $mime]);
        } catch (ConnectionException $e) {
            throw new MetaException('Falha de rede ao falar com a Meta.', 0, null, $e);
        }
        $this->throwIfFailed($response);

        return (string) ($response->json('id') ?? '');
    }

    public function sendDocument(string $to, string $mediaId, string $filename, ?string $caption = null): string
    {
        $document = ['id' => $mediaId, 'filename' => mb_substr($filename, 0, 240)];
        if ($caption !== null && $caption !== '') {
            $document['caption'] = mb_substr($caption, 0, 1024);
        }

        return $this->send(['to' => $to, 'type' => 'document', 'document' => $document]);
    }

    /**
     * Mensagem de modelo aprovado (a única permitida fora da janela de 24 h).
     *
     * @param  list<string>  $params valores das variáveis {{1}}, {{2}}… em ordem
     */
    public function sendTemplate(string $to, string $name, string $language, array $params): string
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];
        if ($params !== []) {
            $template['components'] = [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => $this->param((string) $p)], array_values($params))]];
        }

        return $this->send(['to' => $to, 'type' => 'template', 'template' => $template]);
    }

    /**
     * Cria um modelo para a Meta aprovar.
     *
     * @param  list<string>  $example valores de exemplo das variáveis (a Meta exige)
     * @return array{id:string,status:string}
     */
    public function createTemplate(string $name, string $language, string $category, string $body, array $example): array
    {
        $json = $this->call('post', $this->wabaUrl('message_templates'), [
            'name' => $name, 'language' => $language, 'category' => strtoupper($category),
            'components' => [['type' => 'BODY', 'text' => $body, 'example' => ['body_text' => [array_values($example)]]]],
        ]);

        return ['id' => (string) ($json['id'] ?? ''), 'status' => (string) ($json['status'] ?? 'PENDING')];
    }

    /** @return list<array<string,mixed>> modelos da conta com nome, status e categoria */
    public function listTemplates(): array
    {
        $json = $this->call('get', $this->wabaUrl('message_templates').'?limit=200&fields=name,status,category,language,rejected_reason');

        return array_values(array_filter((array) ($json['data'] ?? []), 'is_array'));
    }

    // ---------------------------------------------------------------- interno

    /** A Meta recusa variável com quebra de linha, tabulação ou mais de 4 espaços seguidos. */
    private function param(string $value): string
    {
        $v = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($v === '' ? '-' : $v, 0, 200);
    }

    private function wabaUrl(string $path): string
    {
        $waba = $this->settings->wabaId();
        if ($waba === '') {
            throw new MetaException('Informe o identificador da conta (WABA) em Admin ▸ Assistente WhatsApp para criar ou consultar modelos.');
        }

        return $this->base().'/'.rawurlencode($waba).'/'.$path;
    }

    /** @param array<string,mixed> $payload @return string id da mensagem enviada */
    private function send(array $payload): string
    {
        $json = $this->call('post', $this->phoneUrl('messages'), ['messaging_product' => 'whatsapp'] + $payload);

        return (string) ($json['messages'][0]['id'] ?? '');
    }

    /** @param array<string,mixed> $json @return array<string,mixed> */
    private function call(string $method, string $url, array $json = []): array
    {
        $this->ensureConfigured();
        try {
            $response = $method === 'get' ? $this->request()->timeout(15)->get($url) : $this->request()->timeout(15)->post($url, $json);
        } catch (ConnectionException $e) {
            throw new MetaException('Falha de rede ao falar com a Meta.', 0, null, $e);
        }
        $this->throwIfFailed($response);
        $this->settings->clearError('meta');

        return (array) ($response->json() ?? []);
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->settings->metaAccessToken())->acceptJson();
    }

    private function throwIfFailed(Response $r): void
    {
        if ($r->successful()) {
            return;
        }
        $err = (array) ($r->json('error') ?? []);
        $detail = (string) ($err['message'] ?? 'HTTP '.$r->status());

        $this->settings->recordError('meta', 'A Meta recusou a chamada: '.$detail);

        throw new MetaException('A Meta recusou a chamada: '.mb_substr($detail, 0, 300), $r->status(), isset($err['code']) ? (int) $err['code'] : null);
    }

    private function ensureConfigured(): void
    {
        if (! $this->settings->metaConfigured()) {
            throw new MetaException('Credenciais da Meta não configuradas (token e número).');
        }
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/'.$this->settings->graphVersion();
    }

    private function phoneUrl(string $path): string
    {
        return $this->base().'/'.rawurlencode($this->settings->metaPhoneNumberId()).'/'.$path;
    }

    private function assertMediaUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $ok = ($parts['scheme'] ?? '') === 'https' && $host !== '' && ! isset($parts['user']);
        if ($ok) {
            $ok = false;
            foreach (self::MEDIA_HOSTS as $suffix) {
                if (str_ends_with($host, $suffix) || $host === ltrim($suffix, '.')) {
                    $ok = true;
                }
            }
        }
        if (! $ok) {
            throw new MetaException('Endereço de mídia recusado.');
        }
    }
}
