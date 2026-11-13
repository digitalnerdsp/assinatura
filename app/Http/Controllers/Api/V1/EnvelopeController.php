<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Presenter;
use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Evidence\EvidenceLogger;
use App\Evidence\RequestContext;
use App\Http\Controllers\Controller;
use App\Jobs\SealEnvelope;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Tenant;
use App\Notifications\SignerNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EnvelopeController extends Controller
{
    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly SignerNotifier $notifier,
        private readonly EvidenceLogger $evidence,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::enum(EnvelopeStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'external_id' => ['nullable', 'string', 'max:120'],
            'folder_id' => ['nullable', 'string', 'max:40'],
            'tag_id' => ['nullable', 'uuid'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = Envelope::query()
            ->where('tenant_id', $this->tenant($request)->getKey())
            ->whereNull('trashed_at')
            ->with(['signers', 'document', 'folder', 'tags'])
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['external_id'] ?? null, fn ($q, $v) => $q->where('external_id', $v))
            ->when($f['folder_id'] ?? null, fn ($q, $v) => $v === 'none' ? $q->whereNull('folder_id') : $q->where('folder_id', $v))
            ->when($f['tag_id'] ?? null, fn ($q, $v) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $v)))
            ->when($f['created_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['created_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->when($f['q'] ?? null, function ($query, string $text) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($text)).'%';
                $query->where(fn ($w) => $w
                    ->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(verification_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(external_id) LIKE ?', [$like])
                    ->orWhereHas('signers', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like])));
            })
            ->latest()
            ->paginate((int) ($f['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Envelope $e) => Presenter::envelope($e))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
            // O PDF vai como arquivo (multipart) OU em base64 (JSON).
            'document' => ['required_without:document_base64', 'file', 'mimes:pdf', 'max:'.((int) config('signing.max_pdf_mb') * 1024)],
            'document_base64' => ['required_without:document', 'string'],
            'document_name' => ['nullable', 'string', 'max:190'],
            'external_id' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array', 'max:30'],
            'signing_order' => ['nullable', Rule::enum(SigningOrder::class)],
            'required_level' => ['nullable', Rule::in(['simple', 'advanced'])],
            'require_otp' => ['nullable', 'boolean'],
            'require_selfie' => ['nullable', 'boolean'],
            'require_document' => ['nullable', 'boolean'],
            'require_geolocation' => ['nullable', 'boolean'],
            'send' => ['nullable', 'boolean'],
            'validity_days' => ['nullable', 'integer', 'between:1,365'],
            'reminder_every_days' => ['nullable', 'integer', 'between:0,30'], // 0 = sem lembretes
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,whatsapp,sms'],
            'folder_id' => ['nullable', 'uuid'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['uuid'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:150'],
            'signers.*.email' => ['required', 'email', 'max:190'],
            'signers.*.phone' => ['nullable', 'string', 'max:30'],
            'signers.*.cpf' => ['nullable', 'string', 'max:14'],
            'signers.*.position' => ['nullable', 'integer', 'between:1,50'],
            'signature_fields' => ['nullable', 'array', 'max:60'],
            'signature_fields.*.signer' => ['required', 'integer', 'min:0', 'max:19'],
            'signature_fields.*.page' => ['required', 'integer', 'min:1', 'max:2000'],
            'signature_fields.*.x' => ['required', 'numeric', 'between:0,1'],
            'signature_fields.*.y' => ['required', 'numeric', 'between:0,1'],
        ]);

        [$pdf, $filename] = $this->pdf($request, $data);
        if ($pdf === null) {
            return $this->error('invalid_document', 'document_base64 não é um base64 válido.', 422);
        }

        try {
            $envelope = $this->envelopes->create(
                $this->tenant($request),
                null,
                $pdf,
                $filename,
                $data['title'],
                array_values($data['signers']),
                SigningOrder::from($data['signing_order'] ?? 'parallel'),
                $data['required_level'] ?? 'simple',
                $data['message'] ?? null,
                array_filter([
                    'otp' => $request->has('require_otp') ? $request->boolean('require_otp') : null,
                    'selfie' => $request->boolean('require_selfie'),
                    'document' => $request->boolean('require_document'),
                    'geolocation' => $request->boolean('require_geolocation'),
                ], fn ($v) => $v !== null),
                $this->options($data),
            );

            if ($request->boolean('send')) {
                $this->dispatchInvites($envelope, $request);
            }
        } catch (FlowException $e) {
            return $this->error($e->reason, $e->getMessage(), 422);
        }

        return response()->json(Presenter::envelope($envelope->fresh()), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(Presenter::envelope($this->find($request, $id)));
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $envelope = $this->find($request, $id);

        try {
            $this->dispatchInvites($envelope, $request);
        } catch (FlowException $e) {
            return $this->error($e->reason, $e->getMessage(), 409);
        }

        return response()->json(Presenter::envelope($envelope->fresh()));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $envelope = $this->find($request, $id);

        try {
            $this->envelopes->cancel($envelope, RequestContext::fromRequest($request));
        } catch (FlowException $e) {
            return $this->error($e->reason, $e->getMessage(), 409);
        }

        return response()->json(Presenter::envelope($envelope->fresh()));
    }

    /** Reenfileira a selagem de um envelope preso em "sealing". */
    public function reseal(Request $request, string $id): JsonResponse
    {
        $envelope = $this->find($request, $id);

        if ($envelope->status !== EnvelopeStatus::Sealing) {
            return $this->error('not_sealing', 'Este envelope não está aguardando selagem.', 409);
        }

        SealEnvelope::dispatch($envelope->getKey());

        return response()->json(Presenter::envelope($envelope), 202);
    }

    public function evidence(Request $request, string $id): JsonResponse
    {
        $envelope = $this->find($request, $id);

        return response()->json([
            'chain_intact' => $this->evidence->isIntact($envelope),
            'events' => $envelope->evidenceEvents()->get()->map(fn ($e) => [
                'sequence' => $e->sequence,
                'type' => $e->type,
                'signer_id' => $e->signer_id,
                'payload' => $e->payload,
                'ip' => $e->ip,
                'user_agent' => $e->user_agent,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'prev_hash' => $e->prev_hash,
                'hash' => $e->hash,
            ]),
        ]);
    }

    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $envelope = $this->find($request, $id);

        if ($envelope->final_path === null) {
            return $this->error('not_ready', 'O documento selado ainda não está disponível.', 409);
        }

        return Storage::disk('local')->download($envelope->final_path, 'assinado-'.$envelope->document->name);
    }

    public function original(Request $request, string $id): StreamedResponse
    {
        $envelope = $this->find($request, $id);

        return Storage::disk('local')->download($envelope->document->storage_path, $envelope->document->name);
    }

    /** Reenvia o aviso (e-mail/WhatsApp/SMS) a um signatário que está na vez de assinar. */
    public function resend(Request $request, string $id, string $signerId): JsonResponse
    {
        $envelope = $this->find($request, $id);
        $signer = $this->signer($envelope, $signerId);
        $url = $signer->signUrl();

        if (! $envelope->status->isOpenForSigning() || $url === null) {
            return $this->error('no_active_link', 'Não há link ativo para reenviar. Gere um novo link.', 409);
        }
        if (! $envelope->activeSigners()->contains(fn (Signer $s) => $s->getKey() === $signer->getKey())) {
            return $this->error('not_your_turn', 'Este signatário ainda não está na vez de assinar (ou já respondeu).', 409);
        }

        $sent = $this->notifier->invite($signer, basename($url));

        return $sent
            ? response()->json(['sent' => true])
            : $this->error('delivery_failed', 'Não foi possível enviar o aviso (canal manual, sem contato válido ou provedor indisponível).', 502);
    }

    /** Gera um link novo (o anterior deixa de funcionar) e o devolve em sign_url. */
    public function newLink(Request $request, string $id, string $signerId): JsonResponse
    {
        $envelope = $this->find($request, $id);
        $signer = $this->signer($envelope, $signerId);

        try {
            $this->envelopes->regenerateLink($signer, RequestContext::fromRequest($request));
        } catch (FlowException $e) {
            return $this->error($e->reason, $e->getMessage(), 409);
        }

        return response()->json(Presenter::signer($signer->fresh(), $envelope->fresh()));
    }

    private function dispatchInvites(Envelope $envelope, Request $request): void
    {
        $this->envelopes->send($envelope, RequestContext::fromRequest($request));
        $this->notifier->inviteActive($envelope->fresh()); // paralelo: todos; sequencial: só o primeiro
    }

    /** @return array{0:?string,1:string} conteúdo do PDF (null = base64 inválido) e nome do arquivo */
    private function pdf(Request $request, array $data): array
    {
        if ($request->hasFile('document')) {
            return [(string) file_get_contents($request->file('document')->getRealPath()), $request->file('document')->getClientOriginalName()];
        }

        $raw = (string) $data['document_base64'];
        $raw = (string) preg_replace('#^data:application/pdf;base64,#', '', $raw);
        $bytes = base64_decode($raw, true);

        return [$bytes === false ? null : $bytes, $data['document_name'] ?? 'documento.pdf'];
    }

    /** @return array<string,mixed> */
    private function options(array $data): array
    {
        $reminder = $data['reminder_every_days'] ?? null;

        return array_filter([
            'validity_days' => $data['validity_days'] ?? null,
            'channels' => $data['channels'] ?? null,
            'folder_id' => $data['folder_id'] ?? null,
            'tags' => $data['tags'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'signature_fields' => isset($data['signature_fields']) ? array_values($data['signature_fields']) : null,
        ], fn ($v) => $v !== null) + ($reminder === null ? [] : ['reminder_every_days' => $reminder === 0 ? null : $reminder]);
    }

    private function find(Request $request, string $id): Envelope
    {
        $query = Envelope::query()
            ->where('tenant_id', $this->tenant($request)->getKey())
            ->with('document');

        // Aceita o id da plataforma ou o external_id do cliente.
        $envelope = \Illuminate\Support\Str::isUuid($id)
            ? (clone $query)->whereKey($id)->first()
            : null;

        return $envelope ?? $query->where('external_id', $id)->firstOrFail();
    }

    private function signer(Envelope $envelope, string $signerId): Signer
    {
        abort_unless(\Illuminate\Support\Str::isUuid($signerId), 404);

        return $envelope->signers()->whereKey($signerId)->firstOrFail();
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }
}
