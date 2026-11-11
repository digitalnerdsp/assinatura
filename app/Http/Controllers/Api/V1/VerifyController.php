<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Evidence\EvidenceLogger;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verificação pública por código (sem chave de API), com os mesmos dados da página /verificar.
 * Com ?sha256=... informa se o arquivo conferido é o original ou o selado.
 */
final class VerifyController extends Controller
{
    public function __invoke(Request $request, string $code, EvidenceLogger $evidence): JsonResponse
    {
        $request->validate(['sha256' => ['nullable', 'regex:/^[A-Fa-f0-9]{64}$/']]);

        $envelope = Envelope::query()->with(['document', 'tenant', 'signers'])->where('verification_code', strtoupper($code))->first();
        if ($envelope === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Código não encontrado.'], 404);
        }

        $body = [
            'code' => $envelope->verification_code,
            'status' => $envelope->status->value,
            'title' => $envelope->title,
            'issuer' => $envelope->tenant->name,
            'completed_at' => $envelope->completed_at?->toIso8601String(),
            'document_sha256' => $envelope->document->sha256,
            'final_sha256' => $envelope->final_sha256,
            'chain_intact' => $evidence->isIntact($envelope),
            'signers' => $envelope->signers->map(fn ($s) => [
                'name' => $s->name, 'status' => $s->status->value, 'signed_at' => $s->signed_at?->toIso8601String(),
            ])->all(),
        ];

        if ($request->filled('sha256')) {
            $hash = strtolower((string) $request->query('sha256'));
            $body['match'] = match (true) {
                $envelope->final_sha256 !== null && hash_equals($envelope->final_sha256, $hash) => 'final',
                hash_equals($envelope->document->sha256, $hash) => 'original',
                default => 'none',
            };
        }

        return response()->json($body);
    }
}
