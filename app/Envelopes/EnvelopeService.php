<?php

declare(strict_types=1);

namespace App\Envelopes;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Enums\SigningOrder;
use App\Envelopes\Exceptions\FlowException;
use App\Evidence\EvidenceLogger;
use App\Evidence\RequestContext;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class EnvelopeService
{
    public function __construct(private readonly EvidenceLogger $evidence)
    {
    }

    /**
     * @param  list<array{name:string,email:string,phone?:string,cpf?:string,role?:string,position?:int}>  $signers
     */
    public function create(
        Tenant $tenant,
        ?User $creator,
        string $pdfContents,
        string $filename,
        string $title,
        array $signers,
        SigningOrder $order = SigningOrder::Parallel,
        string $requiredLevel = 'simple',
        ?string $message = null,
    ): Envelope {
        if (! str_starts_with($pdfContents, '%PDF-')) {
            throw FlowException::because('invalid_document', 'O arquivo enviado não é um PDF válido.');
        }
        if ($signers === []) {
            throw FlowException::because('no_signers', 'Informe ao menos um signatário.');
        }
        if (! in_array($requiredLevel, ['simple', 'advanced'], true)) {
            throw FlowException::because('unsupported_level', 'Nível de assinatura ainda não suportado.');
        }

        return DB::transaction(function () use ($tenant, $creator, $pdfContents, $filename, $title, $signers, $order, $requiredLevel, $message) {
            $path = "tenants/{$tenant->getKey()}/documents/".Str::uuid().'.pdf';
            Storage::disk('local')->put($path, $pdfContents);

            $document = Document::query()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => $filename,
                'storage_path' => $path,
                'sha256' => hash('sha256', $pdfContents),
                'size' => strlen($pdfContents),
            ]);

            $envelope = Envelope::query()->create([
                'tenant_id' => $tenant->getKey(),
                'created_by' => $creator?->getKey(),
                'document_id' => $document->getKey(),
                'title' => $title,
                'message' => $message,
                'status' => EnvelopeStatus::Draft,
                'signing_order' => $order,
                'required_level' => $requiredLevel,
                'verification_code' => strtoupper(Str::random(12)),
            ]);

            foreach (array_values($signers) as $i => $signer) {
                $envelope->signers()->create([
                    'name' => $signer['name'],
                    'email' => mb_strtolower($signer['email']),
                    'phone' => $signer['phone'] ?? null,
                    'cpf' => $signer['cpf'] ?? null,
                    'role' => $signer['role'] ?? 'signer',
                    'position' => $signer['position'] ?? $i + 1,
                    'status' => SignerStatus::Pending,
                ]);
            }

            $this->evidence->record($envelope, 'envelope_created', [
                'document_sha256' => $document->sha256,
                'signers' => count($signers),
                'required_level' => $requiredLevel,
            ]);

            return $envelope;
        });
    }

    /**
     * Envia o envelope. Retorna o token em texto puro de cada signatário (id => token);
     * só o hash é guardado. O token deve ser entregue por e-mail/WhatsApp.
     *
     * @return array<string,string>
     */
    public function send(Envelope $envelope, ?RequestContext $context = null, int $validDays = 30): array
    {
        if ($envelope->status !== EnvelopeStatus::Draft) {
            throw FlowException::because('not_draft', 'Somente rascunhos podem ser enviados.');
        }

        return DB::transaction(function () use ($envelope, $context, $validDays) {
            $tokens = [];

            foreach ($envelope->signers as $signer) {
                $plain = Str::random(48);
                $signer->update(['token_hash' => hash('sha256', $plain)]);
                $tokens[$signer->getKey()] = $plain;
            }

            $envelope->update([
                'status' => EnvelopeStatus::Sent,
                'sent_at' => now(),
                'expires_at' => now()->addDays($validDays),
            ]);

            $this->evidence->record($envelope, 'envelope_sent', ['expires_at' => $envelope->expires_at?->toIso8601String()], null, $context);

            return $tokens;
        });
    }

    public function cancel(Envelope $envelope, ?RequestContext $context = null): void
    {
        if (! $envelope->status->isOpenForSigning() && $envelope->status !== EnvelopeStatus::Draft) {
            throw FlowException::because('not_cancellable', 'Este envelope não pode ser cancelado.');
        }

        $envelope->update(['status' => EnvelopeStatus::Cancelled]);
        $this->evidence->record($envelope, 'envelope_cancelled', [], null, $context);
    }
}
