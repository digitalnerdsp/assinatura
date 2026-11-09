<?php

declare(strict_types=1);

namespace App\Evidence;

use App\Models\Envelope;
use App\Models\EvidenceEvent;
use App\Models\Signer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Trilha de auditoria encadeada por hash (append-only).
 * Cada evento inclui o hash do anterior; qualquer alteração quebra a cadeia.
 */
final class EvidenceLogger
{
    private const TIME_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @param array<string,mixed> $payload */
    public function record(
        Envelope $envelope,
        string $type,
        array $payload = [],
        ?Signer $signer = null,
        ?RequestContext $context = null,
    ): EvidenceEvent {
        return DB::transaction(function () use ($envelope, $type, $payload, $signer, $context) {
            // Serializa escritas por envelope para manter a sequência/hash consistentes.
            Envelope::query()->whereKey($envelope->getKey())->lockForUpdate()->firstOrFail();

            $last = EvidenceEvent::query()
                ->where('envelope_id', $envelope->getKey())
                ->orderByDesc('sequence')
                ->first();

            $sequence = ($last?->sequence ?? 0) + 1;
            $prevHash = $last?->hash ?? self::genesisHash($envelope);
            $occurredAt = Carbon::now('UTC')->startOfSecond(); // precisão de 1s: igual ao que o banco devolve

            $hash = self::computeHash(
                $prevHash,
                $sequence,
                $type,
                $signer?->getKey(),
                $occurredAt->format(self::TIME_FORMAT),
                $context?->ip,
                $context?->userAgent,
                $payload,
            );

            return EvidenceEvent::query()->create([
                'envelope_id' => $envelope->getKey(),
                'signer_id' => $signer?->getKey(),
                'sequence' => $sequence,
                'type' => $type,
                'payload' => $payload,
                'ip' => $context?->ip,
                'user_agent' => $context?->userAgent,
                'occurred_at' => $occurredAt,
                'prev_hash' => $prevHash,
                'hash' => $hash,
            ]);
        });
    }

    /** Retorna null se a cadeia estiver íntegra, ou a sequência do primeiro evento inválido. */
    public function firstBrokenSequence(Envelope $envelope): ?int
    {
        $prev = self::genesisHash($envelope);

        foreach (EvidenceEvent::query()->where('envelope_id', $envelope->getKey())->orderBy('sequence')->cursor() as $event) {
            $expected = self::computeHash(
                $prev,
                (int) $event->sequence,
                $event->type,
                $event->signer_id,
                Carbon::parse($event->occurred_at)->utc()->format(self::TIME_FORMAT),
                $event->ip,
                $event->user_agent,
                $event->payload ?? [],
            );

            if ($event->prev_hash !== $prev || $event->hash !== $expected) {
                return (int) $event->sequence;
            }

            $prev = $event->hash;
        }

        return null;
    }

    public function isIntact(Envelope $envelope): bool
    {
        return $this->firstBrokenSequence($envelope) === null;
    }

    public static function genesisHash(Envelope $envelope): string
    {
        return hash('sha256', 'genesis|'.$envelope->getKey());
    }

    /** @param array<string,mixed> $payload */
    public static function computeHash(
        string $prevHash,
        int $sequence,
        string $type,
        ?string $signerId,
        string $occurredAt,
        ?string $ip,
        ?string $userAgent,
        array $payload,
    ): string {
        return hash('sha256', implode('|', [
            $prevHash,
            $sequence,
            $type,
            $signerId ?? '',
            $occurredAt,
            $ip ?? '',
            $userAgent ?? '',
            self::canonicalJson($payload),
        ]));
    }

    /** @param array<mixed> $data */
    private static function canonicalJson(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $value[$k] = $sort($v);
                }
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };

        return json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
