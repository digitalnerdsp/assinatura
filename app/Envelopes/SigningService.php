<?php

declare(strict_types=1);

namespace App\Envelopes;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Enums\SigningOrder;
use App\Envelopes\Exceptions\FlowException;
use App\Evidence\EvidenceLogger;
use App\Evidence\RequestContext;
use App\Jobs\SealEnvelope;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\SignerAttachment;
use App\Notifications\SignerNotifier;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Fluxo do signatário: abrir link → OTP → assinar/recusar. */
final class SigningService
{
    private const OTP_TTL_MINUTES = 10;
    private const OTP_MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly EvidenceLogger $evidence,
        private readonly WebhookDispatcher $webhooks,
        private readonly LifecycleService $lifecycle,
        private readonly SignerNotifier $notifier,
    ) {
    }

    /** Em documentos sequenciais, é a vez deste signatário? (Em paralelo, sempre.) */
    public function isMyTurn(Signer $signer): bool
    {
        return $signer->envelope->activeSigners()->contains(fn (Signer $s) => $s->getKey() === $signer->getKey());
    }

    public function findByToken(string $token): Signer
    {
        $signer = Signer::query()->where('token_hash', hash('sha256', $token))->first();

        if ($signer === null) {
            throw FlowException::because('invalid_token', 'Link de assinatura inválido.');
        }

        $this->assertOpen($signer);

        return $signer;
    }

    public function view(Signer $signer, ?RequestContext $context = null): void
    {
        $this->assertOpen($signer);

        $first = $signer->status === SignerStatus::Pending;
        if ($first) {
            $signer->update(['status' => SignerStatus::Viewed, 'viewed_at' => now()]);
        }

        $this->evidence->record($signer->envelope, 'document_viewed', [], $signer, $context);

        if ($first) {
            $this->webhooks->dispatch($signer->envelope, 'signer.viewed', $signer);
        }
    }

    /** Gera o OTP e devolve o código em texto puro para o notificador entregar (e-mail/SMS/WhatsApp). */
    public function requestOtp(Signer $signer, string $channel = 'email', ?RequestContext $context = null): string
    {
        $this->assertOpen($signer);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $signer->update([
            'otp_hash' => $this->hashOtp($signer, $code),
            'otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'otp_attempts' => 0,
            'otp_verified_at' => null,
        ]);

        $this->evidence->record($signer->envelope, 'otp_sent', ['channel' => $channel], $signer, $context);

        return $code;
    }

    public function verifyOtp(Signer $signer, string $code, ?RequestContext $context = null): void
    {
        $this->assertOpen($signer);

        if ($signer->otp_hash === null || $signer->otp_expires_at === null || $signer->otp_expires_at->isPast()) {
            throw FlowException::because('otp_expired', 'Código expirado. Solicite um novo.');
        }

        if ($signer->otp_attempts >= self::OTP_MAX_ATTEMPTS) {
            throw FlowException::because('otp_locked', 'Muitas tentativas. Solicite um novo código.');
        }

        if (! hash_equals($signer->otp_hash, $this->hashOtp($signer, $code))) {
            $signer->increment('otp_attempts');
            $this->evidence->record($signer->envelope, 'otp_failed', ['attempt' => $signer->otp_attempts], $signer, $context);

            throw FlowException::because('otp_invalid', 'Código incorreto.');
        }

        $signer->update(['otp_verified_at' => now(), 'otp_hash' => null]);
        $this->evidence->record($signer->envelope, 'otp_verified', [], $signer, $context);
    }

    /**
     * @param  'drawn'|'typed'  $type
     */
    /**
     * @param  array{type:string,data:string}|null  $initials  visto (rubrica), exigido se o signatário tem campo Visto
     * @param  array<string,string>  $texts  id do campo de texto => texto, exigido para cada campo Texto do signatário
     */
    public function sign(Signer $signer, string $type, string $data, bool $acceptedTerms, ?RequestContext $context = null, ?array $initials = null, array $texts = []): void
    {
        $this->assertOpen($signer);

        if (! in_array($type, ['drawn', 'typed'], true) || trim($data) === '') {
            throw FlowException::because('invalid_signature', 'Assinatura inválida.');
        }
        if (! $acceptedTerms) {
            throw FlowException::because('terms_required', 'É necessário aceitar os termos para assinar.');
        }

        $envelope = $signer->envelope;

        $pending = $this->pendingSteps($signer);
        if ($pending !== []) {
            $messages = [
                'otp' => 'Confirme o código de verificação antes de assinar.',
                'selfie' => 'Tire sua selfie antes de assinar.',
                'document' => 'Envie a foto do seu documento antes de assinar.',
                'geolocation' => 'Permita o compartilhamento da localização antes de assinar.',
            ];

            throw FlowException::because($pending[0].'_required', $messages[$pending[0]]);
        }

        $this->assertMyTurn($signer, $envelope);

        $this->recordSignature($signer, $type, $data, $context, [], null, $this->fieldInputs($signer, $initials, $texts));
    }

    /**
     * Visto e textos que o signatário precisa informar por causa dos campos que lhe foram dados. Só conta o que é dele:
     * ids de campos de outros signatários são ignorados.
     *
     * @param  array{type:string,data:string}|null  $initials
     * @param  array<string,string>  $texts
     * @return array{initials:?array{type:string,data:string},texts:array<string,string>}
     */
    private function fieldInputs(Signer $signer, ?array $initials, array $texts): array
    {
        $fields = $signer->envelope->signatureFields()->where('signer_id', $signer->getKey())->whereIn('type', ['initials', 'text'])->get();

        $mark = null;
        if ($fields->contains('type', 'initials')) {
            $kind = $initials['type'] ?? null;
            $value = trim((string) ($initials['data'] ?? ''));
            $valid = ($kind === 'drawn' && str_starts_with($value, 'data:image/png;base64,') && strlen($value) <= 300000
                    && base64_decode(substr($value, strlen('data:image/png;base64,')), true) !== false)
                || ($kind === 'typed' && $value !== '' && mb_strlen($value) <= 30);
            if (! $valid) {
                throw FlowException::because('initials_required', 'Faça o seu visto (rubrica) para assinar.');
            }
            $mark = ['type' => $kind, 'data' => $value];
        }

        $values = [];
        foreach ($fields->where('type', 'text') as $field) {
            $text = trim((string) ($texts[(string) $field->getKey()] ?? ''));
            if ($text === '' || mb_strlen($text) > 200) {
                throw FlowException::because('text_required', 'Preencha todos os campos de texto (até 200 caracteres) para assinar.');
            }
            $values[(string) $field->getKey()] = $text;
        }

        return ['initials' => $mark, 'texts' => $values];
    }

    /**
     * Grava a assinatura (já validada) e cuida da conclusão: evento de prova, selagem quando todos assinaram,
     * webhook e convite do próximo da fila. Usado por sign() e pela assinatura do remetente, que não passa por
     * link nem verificações. `$evidence` é somado ao evento `document_signed`; `$level` troca o nível da assinatura.
     *
     * @param  array<string,mixed>  $evidence
     */
    public function recordSignature(Signer $signer, string $type, string $data, ?RequestContext $context = null, array $evidence = [], ?string $level = null, array $extras = []): void
    {
        $envelope = $signer->envelope;

        $allSigned = DB::transaction(function () use ($signer, $envelope, $type, $data, $context, $evidence, $level, $extras) {
            // Trava o signatário para impedir assinatura dupla concorrente.
            $fresh = Signer::query()->whereKey($signer->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->status === SignerStatus::Signed) {
                throw FlowException::because('already_signed', 'Você já assinou este documento.');
            }

            $now = now();
            $level ??= $fresh->requiresAdvanced() ? 'advanced' : 'simple'; // avançada só se este signatário passou por verificação adicional (já validada acima)

            // Com vários arquivos, a assinatura vale para o conjunto: o hash guardado é o do pacote (n:hash de cada arquivo).
            $files = $envelope->files();
            $bundle = $files->count() > 1 ? hash('sha256', $files->map(fn ($f) => "{$f->n}:{$f->sha256}")->implode("\n")) : $envelope->document->sha256;

            $fresh->signature()->create([
                'type' => $type,
                'data' => $data,
                'data_sha256' => hash('sha256', $data),
                'document_sha256' => $bundle,
                'level' => $level,
                'signed_at' => $now,
                'initials_type' => $extras['initials']['type'] ?? null,
                'initials_data' => $extras['initials']['data'] ?? null,
            ]);

            // Textos dos campos Texto: gravados junto da assinatura, antes de qualquer selagem.
            foreach ((array) ($extras['texts'] ?? []) as $fieldId => $text) {
                $envelope->signatureFields()->where('signer_id', $fresh->getKey())->whereKey($fieldId)->update(['value' => $text]);
            }

            $fresh->update(['status' => SignerStatus::Signed, 'signed_at' => $now]);

            $this->evidence->record($envelope, 'document_signed', [
                'type' => $type,
                'level' => $level,
                'document_sha256' => $envelope->document->sha256,
                'signature_sha256' => hash('sha256', $data),
            ] + ($files->count() > 1 ? ['bundle_sha256' => $bundle, 'documents' => $files->map(fn ($f) => ['n' => $f->n, 'name' => $f->name, 'sha256' => $f->sha256])->all()] : []) + ((! empty($extras['initials']) || ! empty($extras['texts'])) ? ['fields' => [
                'initials_sha256' => ! empty($extras['initials']) ? hash('sha256', $extras['initials']['data']) : null,
                'texts_sha256' => ! empty($extras['texts']) ? hash('sha256', json_encode($extras['texts'], JSON_UNESCAPED_UNICODE)) : null,
            ]] : []) + $evidence, $fresh, $context);

            $pending = $envelope->signers()->whereIn('role', Envelope::PARTICIPANT_ROLES)->where('status', '!=', SignerStatus::Signed->value)->count();

            if ($pending === 0) {
                $envelope->update(['status' => EnvelopeStatus::Sealing]);
                $this->evidence->record($envelope, 'all_signed', []);

                return true;
            }

            $envelope->update(['status' => EnvelopeStatus::InProgress]);

            return false;
        });

        $this->webhooks->dispatch($envelope, 'signer.signed', $signer->fresh());

        if ($allSigned) {
            SealEnvelope::dispatch($envelope->getKey())->afterCommit();
        } elseif ($envelope->signing_order === SigningOrder::Sequential) {
            $this->notifier->inviteActive($envelope); // avisa o próximo da fila
        }
    }

    /**
     * Etapas de verificação exigidas pelo documento que o signatário ainda não cumpriu,
     * na ordem em que são apresentadas.
     *
     * @return list<'otp'|'selfie'|'document'|'geolocation'>
     */
    public function pendingSteps(Signer $signer): array
    {
        $envelope = $signer->envelope;
        $signer->loadMissing('attachments');
        $pending = [];

        if ($signer->requires('otp') && $signer->otp_verified_at === null) {
            $pending[] = 'otp';
        }
        if ($signer->requires('selfie') && $signer->attachment(SignerAttachment::SELFIE) === null) {
            $pending[] = 'selfie';
        }
        if ($signer->requires('document') && $signer->attachment(SignerAttachment::DOCUMENT) === null) {
            $pending[] = 'document';
        }
        if ($signer->requires('geolocation') && $signer->geolocation_at === null) {
            $pending[] = 'geolocation';
        }

        return $pending;
    }

    /** Selfie capturada na tela. $binary = bytes da imagem (JPEG/PNG/WebP). */
    public function submitSelfie(Signer $signer, string $binary, string $source = 'camera', ?RequestContext $context = null): void
    {
        $this->storeImage($signer, SignerAttachment::SELFIE, $binary, $source, 'selfie_captured', $context);
    }

    /** Foto do documento de identificação (RG/CNH). */
    public function submitDocument(Signer $signer, string $binary, string $source = 'upload', ?RequestContext $context = null): void
    {
        $this->storeImage($signer, SignerAttachment::DOCUMENT, $binary, $source, 'document_photo_captured', $context);
    }

    public function recordGeolocation(Signer $signer, float $lat, float $lng, ?float $accuracy, ?RequestContext $context = null): void
    {
        $this->assertOpen($signer);

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw FlowException::because('invalid_geolocation', 'Localização inválida.');
        }

        $signer->update(['geolocation_at' => now()]);
        $this->evidence->record($signer->envelope, 'geolocation_captured', [
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
            'accuracy_m' => $accuracy === null ? null : round($accuracy, 1),
        ], $signer, $context);
    }

    private function storeImage(Signer $signer, string $kind, string $binary, string $source, string $event, ?RequestContext $context): void
    {
        $this->assertOpen($signer);

        if (strlen($binary) > 5 * 1024 * 1024) {
            throw FlowException::because('image_too_large', 'A imagem é grande demais (máx. 5 MB).');
        }

        $info = @getimagesizefromstring($binary);
        $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

        if ($info === false || ! isset($extensions[$info[2]])) {
            throw FlowException::because('invalid_image', 'Envie uma imagem JPG, PNG ou WebP.');
        }
        if ($info[0] < 200 || $info[1] < 200) {
            throw FlowException::because('image_too_small', 'A imagem tem resolução muito baixa.');
        }

        $envelope = $signer->envelope;
        $sha = hash('sha256', $binary);
        $path = "tenants/{$envelope->tenant_id}/signers/{$signer->getKey()}/{$kind}-".substr($sha, 0, 16).'.'.$extensions[$info[2]];
        Storage::disk('local')->put($path, $binary);

        DB::transaction(function () use ($signer, $kind, $path, $info, $binary, $sha, $source, $event, $context, $envelope) {
            $previous = (int) SignerAttachment::query()->where('signer_id', $signer->getKey())->where('kind', $kind)->value('size');
            SignerAttachment::query()->updateOrCreate(
                ['signer_id' => $signer->getKey(), 'kind' => $kind],
                ['path' => $path, 'mime' => $info['mime'], 'size' => strlen($binary), 'sha256' => $sha, 'source' => $source],
            );
            app(\App\Plans\StorageUsage::class)->add($envelope->tenant, strlen($binary) - $previous);

            $this->evidence->record($envelope, $event, [
                'sha256' => $sha, 'size' => strlen($binary), 'mime' => $info['mime'],
                'width' => $info[0], 'height' => $info[1], 'source' => $source,
            ], $signer, $context);
        });

        $signer->unsetRelation('attachments');
    }

    public function refuse(Signer $signer, string $reason, ?RequestContext $context = null): void
    {
        $this->assertOpen($signer);

        if (! $signer->envelope->allow_refuse) {
            throw FlowException::because('refuse_disabled', 'Este documento não permite recusar a assinatura.');
        }

        DB::transaction(function () use ($signer, $reason, $context) {
            $signer->update(['status' => SignerStatus::Refused, 'refused_reason' => $reason]);
            $signer->envelope->update(['status' => EnvelopeStatus::Refused]);
            $this->evidence->record($signer->envelope, 'signer_refused', ['reason' => $reason], $signer, $context);
        });

        $this->webhooks->dispatch($signer->envelope, 'signer.refused', $signer->fresh());
    }

    private function assertOpen(Signer $signer): void
    {
        $envelope = $signer->envelope;

        $this->lifecycle->expire($envelope);

        if (! $envelope->status->isOpenForSigning()) {
            throw FlowException::because('document_closed', 'Este documento não está mais disponível para assinatura.');
        }

        if (in_array($signer->status, [SignerStatus::Signed, SignerStatus::Refused], true)) {
            throw FlowException::because('signer_done', 'Você já respondeu a este documento.');
        }
    }

    private function assertMyTurn(Signer $signer, Envelope $envelope): void
    {
        if ($envelope->signing_order !== SigningOrder::Sequential) {
            return;
        }

        $blocking = $envelope->signers()
            ->whereIn('role', Envelope::PARTICIPANT_ROLES)
            ->where('position', '<', $signer->position)
            ->where('status', '!=', SignerStatus::Signed->value)
            ->exists();

        if ($blocking) {
            throw FlowException::because('not_your_turn', 'Aguarde os signatários anteriores assinarem.');
        }
    }

    private function hashOtp(Signer $signer, string $code): string
    {
        return hash_hmac('sha256', $signer->getKey().'|'.$code, (string) config('app.key'));
    }
}
