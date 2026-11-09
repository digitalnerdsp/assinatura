<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Evidence\EvidenceLogger;
use App\Evidence\RequestContext;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'signing_order' => ['nullable', Rule::enum(SigningOrder::class)],
            'required_level' => ['nullable', Rule::in(['simple', 'advanced'])],
            'send' => ['nullable', 'boolean'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:150'],
            'signers.*.email' => ['required', 'email', 'max:190'],
            'signers.*.phone' => ['nullable', 'string', 'max:30'],
            'signers.*.cpf' => ['nullable', 'string', 'max:14'],
        ]);

        try {
            $envelope = $this->envelopes->create(
                $this->tenant($request),
                null,
                (string) file_get_contents($request->file('document')->getRealPath()),
                $request->file('document')->getClientOriginalName(),
                $data['title'],
                array_values($data['signers']),
                SigningOrder::from($data['signing_order'] ?? 'parallel'),
                $data['required_level'] ?? 'simple',
                $data['message'] ?? null,
            );

            if ($request->boolean('send')) {
                $this->dispatchInvites($envelope, $request);
            }
        } catch (FlowException $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($envelope->fresh(['signers'])), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->present($this->find($request, $id)->load('signers')));
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $envelope = $this->find($request, $id);

        try {
            $this->dispatchInvites($envelope, $request);
        } catch (FlowException $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], 409);
        }

        return response()->json($this->present($envelope->fresh(['signers'])));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $envelope = $this->find($request, $id);

        try {
            $this->envelopes->cancel($envelope, RequestContext::fromRequest($request));
        } catch (FlowException $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], 409);
        }

        return response()->json($this->present($envelope->fresh(['signers'])));
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
            return response()->json(['error' => 'not_ready', 'message' => 'O documento selado ainda não está disponível.'], 409);
        }

        return Storage::disk('local')->download($envelope->final_path, 'assinado-'.$envelope->document->name);
    }

    private function dispatchInvites(Envelope $envelope, Request $request): void
    {
        $tokens = $this->envelopes->send($envelope, RequestContext::fromRequest($request));

        foreach ($envelope->signers as $signer) {
            $this->notifier->invite($signer, $tokens[$signer->getKey()]);
        }
    }

    private function find(Request $request, string $id): Envelope
    {
        return Envelope::query()
            ->where('tenant_id', $this->tenant($request)->getKey())
            ->with('document')
            ->findOrFail($id);
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }

    /** @return array<string,mixed> */
    private function present(Envelope $e): array
    {
        return [
            'id' => $e->getKey(),
            'title' => $e->title,
            'status' => $e->status->value,
            'signing_order' => $e->signing_order->value,
            'required_level' => $e->required_level,
            'verification_code' => $e->verification_code,
            'verification_url' => route('verify.show', $e->verification_code),
            'sent_at' => $e->sent_at?->toIso8601String(),
            'expires_at' => $e->expires_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
            'final_sha256' => $e->final_sha256,
            'signers' => $e->signers->map(fn ($s) => [
                'id' => $s->getKey(),
                'name' => $s->name,
                'email' => $s->email,
                'position' => $s->position,
                'status' => $s->status->value,
                'signed_at' => $s->signed_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
