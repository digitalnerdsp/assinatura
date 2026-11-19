<?php

declare(strict_types=1);

namespace App\Assistant\Channel;

use App\Models\WhatsAppInbound;

/** Mensagem recebida, já em forma simples: o orquestrador não conhece o formato da Meta. */
final readonly class InboundMessage
{
    public function __construct(
        public string $waMessageId,
        public string $from, // E.164 sem "+"
        public string $type, // text | button | list | document | other
        public ?string $text = null,
        public ?string $payloadId = null,
        public ?string $mediaId = null,
        public ?string $mime = null,
        public ?string $filename = null,
        public int $timestamp = 0,
    ) {
    }

    /** @param array<string,mixed> $m um item de `messages[]` do webhook */
    public static function fromMeta(array $m): self
    {
        $base = ['waMessageId' => (string) ($m['id'] ?? ''), 'from' => preg_replace('/\D/', '', (string) ($m['from'] ?? '')) ?? '', 'timestamp' => (int) ($m['timestamp'] ?? 0)];
        $type = (string) ($m['type'] ?? '');

        if ($type === 'text') {
            return new self(...$base, type: 'text', text: self::str($m['text']['body'] ?? null));
        }
        if ($type === 'interactive') {
            $i = (array) ($m['interactive'] ?? []);
            $kind = (string) ($i['type'] ?? '');
            if ($kind === 'button_reply') {
                return new self(...$base, type: 'button', text: self::str($i['button_reply']['title'] ?? null), payloadId: self::str($i['button_reply']['id'] ?? null));
            }
            if ($kind === 'list_reply') {
                return new self(...$base, type: 'list', text: self::str($i['list_reply']['title'] ?? null), payloadId: self::str($i['list_reply']['id'] ?? null));
            }
        }
        if ($type === 'document') {
            $d = (array) ($m['document'] ?? []);

            return new self(...$base, type: 'document', text: self::str($d['caption'] ?? null), mediaId: self::str($d['id'] ?? null), mime: self::str($d['mime_type'] ?? null), filename: self::str($d['filename'] ?? null));
        }

        return new self(...$base, type: 'other');
    }

    public static function fromRow(WhatsAppInbound $row): self
    {
        $p = (array) $row->payload;

        return new self($row->wa_message_id, $row->from_phone, $row->type, $p['text'] ?? null, $p['payloadId'] ?? null, $p['mediaId'] ?? null, $p['mime'] ?? null, $p['filename'] ?? null, (int) ($row->received_at?->getTimestamp() ?? 0));
    }

    /** O que vai para o banco: só o necessário, nunca o conteúdo de arquivos. @return array<string,string> */
    public function payload(): array
    {
        return array_filter(['text' => $this->text, 'payloadId' => $this->payloadId, 'mediaId' => $this->mediaId, 'mime' => $this->mime, 'filename' => $this->filename], fn ($v) => $v !== null);
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? mb_substr($v, 0, 4096) : null;
    }
}
