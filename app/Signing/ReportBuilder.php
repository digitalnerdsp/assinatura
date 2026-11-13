<?php

declare(strict_types=1);

namespace App\Signing;

use App\Models\Envelope;
use App\Models\EvidenceEvent;
use App\Models\Signer;
use Illuminate\Support\Carbon;

/**
 * Monta os dados da página de relatório de assinaturas que o serviço de selagem anexa ao PDF final.
 *
 * Privacidade: o relatório vai para TODOS os envolvidos. Por isso não leva a imagem da selfie nem do
 * documento (só que foram registradas e o hash), mostra o CPF parcialmente e arredonda a localização
 * (~100 m). A prova completa continua no painel do remetente.
 */
final class ReportBuilder
{
    private const TZ = 'America/Sao_Paulo';

    private const LABELS = [
        'envelope_created' => 'Envelope criado', 'envelope_sent' => 'Liberado para assinatura',
        'document_viewed' => 'Documento visualizado', 'otp_sent' => 'Código enviado', 'otp_failed' => 'Código incorreto',
        'otp_verified' => 'Código confirmado', 'selfie_captured' => 'Selfie registrada',
        'document_photo_captured' => 'Foto do documento registrada', 'geolocation_captured' => 'Localização registrada',
        'document_signed' => 'Documento assinado', 'all_signed' => 'Todas as assinaturas coletadas',
        'signer_refused' => 'Assinatura recusada', 'link_regenerated' => 'Novo link gerado',
        'seal_failed' => 'Falha ao selar', 'envelope_cancelled' => 'Envelope cancelado', 'envelope_expired' => 'Envelope expirado',
        'reminder_sent' => 'Lembrete enviado',
    ];

    /** @return array<string,mixed> */
    public function build(Envelope $envelope): array
    {
        $envelope->loadMissing(['tenant', 'document', 'signers.signature', 'signers.attachments']);
        $events = $envelope->evidenceEvents()->get();

        return [
            'code' => $envelope->verification_code,
            'url' => route('verify.show', $envelope->verification_code),
            'tenant' => $envelope->tenant->name,
            'title' => $envelope->title,
            'document_name' => $envelope->document->name,
            'document_sha256' => $envelope->document->sha256,
            'chain_hash' => $events->last()?->hash,
            'generated_at' => $this->at(now()).' (BRT)',
            // remetente, signatários e testemunhas (nessa ordem; dentro de cada grupo, por posição)
            'signers' => $envelope->signers
                ->sortBy(fn (Signer $s) => [$s->isSender() ? 0 : ($s->isWitness() ? 2 : 1), $s->position, $s->getKey()])
                ->map(fn (Signer $s) => $this->signer($s, $events))->values()->all(),
            'events' => $this->events($envelope, $events),
            'placements' => $this->placements($envelope),
        ];
    }

    /**
     * Assinaturas a desenhar nas páginas do documento (só de quem já assinou). Desenho vai em base64 (PNG pequeno);
     * assinatura digitada vai como texto. Posição em frações da página, origem no topo-esquerda.
     *
     * @return list<array<string,mixed>>
     */
    private function placements(Envelope $envelope): array
    {
        $envelope->loadMissing(['signatureFields', 'signers.signature']);
        $signers = $envelope->signers->keyBy(fn (Signer $s) => $s->getKey());
        $out = [];

        foreach ($envelope->signatureFields as $field) {
            $signer = $signers->get($field->signer_id);
            $sig = $signer?->signature;
            if ($signer === null || $sig === null || $signer->signed_at === null) {
                continue;
            }

            $drawn = $sig->type === 'drawn';
            $out[] = [
                'page' => $field->page,
                'x' => $field->x, 'y' => $field->y, 'width' => $field->width, 'height' => $field->height,
                'name' => $signer->name,
                'signed_at' => $this->at($signer->signed_at),
                'type' => $sig->type,
                'image' => $drawn ? substr((string) $sig->data, (int) strpos((string) $sig->data, ',') + 1) : null,
                'text' => $drawn ? null : $sig->data,
            ];
        }

        return $out;
    }

    /** @param \Illuminate\Support\Collection<int,EvidenceEvent> $events */
    private function signer(Signer $s, $events): array
    {
        $mine = $events->where('signer_id', $s->getKey());
        $signed = $mine->firstWhere('type', 'document_signed');
        $geo = $mine->firstWhere('type', 'geolocation_captured');

        $checks = [];
        if ($mine->contains('type', 'otp_verified')) {
            $checks[] = 'Código por e-mail';
        }
        if ($selfie = $mine->firstWhere('type', 'selfie_captured')) {
            $checks[] = 'Selfie ('.($selfie->payload['source'] === 'camera' ? 'câmera' : 'upload').')';
        }
        if ($doc = $mine->firstWhere('type', 'document_photo_captured')) {
            $checks[] = 'Foto do documento (SHA-256 '.substr((string) ($doc->payload['sha256'] ?? ''), 0, 12).'...)';
        }
        if ($geo) {
            $checks[] = 'Localização';
        }

        $sig = $s->signature;

        return [
            'name' => $s->name,
            'role' => $s->isSender() ? 'remetente' : ($s->isWitness() ? 'testemunha' : 'signatário'),
            'email' => $s->email,
            'cpf' => $this->maskCpf($s->cpf),
            'status' => $this->statusLabel($s),
            'signed_at' => $s->signed_at ? $this->at($s->signed_at) : null,
            'signature' => $sig
                ? ($sig->type === 'drawn' ? 'Desenhada' : 'Digitada').' - nível '.($sig->level === 'advanced' ? 'avançado' : 'simples')
                : null,
            'ip' => $signed?->ip,
            'device' => $signed?->user_agent ? mb_substr((string) $signed->user_agent, 0, 140) : null,
            'checks' => $checks,
            'geo' => $geo ? sprintf(
                '%s, %s (±%s m)',
                number_format((float) $geo->payload['lat'], 3, '.', ''),
                number_format((float) $geo->payload['lng'], 3, '.', ''),
                $geo->payload['accuracy_m'] ?? '?',
            ) : null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int,EvidenceEvent>  $events
     * @return list<array<string,mixed>>
     */
    private function events(Envelope $envelope, $events): array
    {
        $names = $envelope->signers->pluck('name', 'id');
        $views = $events->where('type', 'document_viewed')->groupBy('signer_id')->map->count();
        $seenView = [];
        $rows = [];

        foreach ($events as $e) {
            if ($e->type === 'document_viewed') {
                // Resume as visualizações repetidas em uma única linha.
                if (isset($seenView[$e->signer_id])) {
                    continue;
                }
                $seenView[$e->signer_id] = true;
            }

            $label = self::LABELS[$e->type] ?? $e->type;
            if ($e->type === 'document_viewed' && ($views[$e->signer_id] ?? 1) > 1) {
                $label .= ' ('.$views[$e->signer_id].' vezes)';
            }

            $rows[] = [
                'seq' => $e->sequence,
                'label' => $label,
                'who' => $e->signer_id ? ($names[$e->signer_id] ?? null) : null,
                'at' => $this->at($e->occurred_at),
                'ip' => $e->ip,
            ];
        }

        return $rows;
    }

    private function at(\DateTimeInterface $date): string
    {
        return Carbon::instance($date)->timezone(self::TZ)->format('d/m/Y H:i:s');
    }

    private function statusLabel(Signer $s): string
    {
        return match ($s->status->value) {
            'signed' => 'Assinado', 'refused' => 'Recusou', 'viewed' => 'Visualizou', default => 'Pendente',
        };
    }

    private function maskCpf(?string $cpf): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $cpf);

        return strlen($digits) === 11
            ? '***.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-**'
            : null;
    }
}
