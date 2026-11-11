<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotência por cabeçalho "Idempotency-Key": repetir o mesmo POST devolve a resposta original (com o cabeçalho
 * "Idempotent-Replayed: true") em vez de criar outro envelope. Reusar a chave com um corpo diferente é erro 422.
 * Só respostas 2xx e 4xx de validação/negócio são guardadas; 5xx não, para permitir nova tentativa.
 */
final class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $tenant = $request->attributes->get('tenant');

        if ($key === null || ! $tenant instanceof Tenant) {
            return $next($request);
        }

        if (mb_strlen($key) > 120) {
            return response()->json(['error' => 'invalid_idempotency_key', 'message' => 'Idempotency-Key aceita até 120 caracteres.'], 422);
        }

        $hash = $this->fingerprint($request);
        $row = DB::table('api_idempotency_keys')->where('tenant_id', $tenant->getKey())->where('key', $key)->first();

        if ($row !== null) {
            if (! hash_equals($row->request_hash, $hash)) {
                return response()->json(['error' => 'idempotency_key_reused', 'message' => 'Esta Idempotency-Key já foi usada com outro conteúdo.'], 422);
            }

            return response($row->response_body, $row->response_status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            try {
                DB::table('api_idempotency_keys')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->getKey(),
                    'key' => $key,
                    'request_hash' => $hash,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => (string) $response->getContent(),
                    'created_at' => now(),
                ]);
            } catch (Throwable) {
                // Duas requisições simultâneas com a mesma chave: a outra gravou primeiro; esta resposta segue normalmente.
            }
        }

        return $response;
    }

    private function fingerprint(Request $request): string
    {
        $input = $request->all();
        array_walk_recursive($input, function (&$value) {
            if ($value instanceof UploadedFile) {
                $value = 'file:'.hash_file('sha256', $value->getRealPath());
            }
        });

        return hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($input));
    }
}
