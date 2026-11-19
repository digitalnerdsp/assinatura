<?php

declare(strict_types=1);

namespace App\Assistant\Linking;

use App\Models\User;
use App\Models\WhatsAppLinkAttempt;
use App\Models\WhatsAppLinkCode;
use App\Plans\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * Vínculo do número do WhatsApp a um usuário. A pessoa gera um código no perfil e o envia ao número da plataforma pelo
 * próprio WhatsApp: isso prova a posse do número e abre a janela de 24 h. O código vale 15 minutos e uma vez, é guardado
 * só como hash e nunca é registrado em log. 5 tentativas erradas do mesmo número bloqueiam esse número por 1 hora.
 */
final class WhatsAppLinker
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem I, O, 0 e 1 (confundem)
    private const TTL_MINUTES = 15;
    private const MAX_ATTEMPTS = 5;
    private const BLOCK_MINUTES = 60;

    /** Gera o código (8 caracteres) e invalida os anteriores do usuário. Devolve o código uma única vez. */
    public function start(User $user): string
    {
        do { // sempre com letra e número: palavras comuns nunca viram "código"
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (! $this->valid($code));

        DB::transaction(function () use ($user, $code): void {
            WhatsAppLinkCode::query()->where('user_id', $user->getKey())->delete();
            WhatsAppLinkCode::query()->create([
                'user_id' => $user->getKey(), 'code_hash' => $this->hash($code), 'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
        });

        return $code;
    }

    /** Número desconhecido mandou algo que parece o código: vincula e devolve o usuário; senão conta a tentativa errada. */
    public function claim(string $fromPhone, string $text): ?User
    {
        $phone = $this->normalize($fromPhone);
        if ($phone === '' || $this->isBlocked($phone)) {
            return null;
        }
        $candidate = $this->extract($text);
        if ($candidate === null) {
            return null; // conversa comum não conta como tentativa
        }

        return DB::transaction(function () use ($phone, $candidate): ?User {
            $hash = $this->hash($candidate);
            $match = null;
            foreach (WhatsAppLinkCode::query()->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->get() as $code) {
                if (hash_equals($code->code_hash, $hash)) {
                    $match = $code;
                }
            }

            $user = $match?->user;
            if ($match === null || $user === null || ! $user->isActive() || $user->isPendingInvite() || ! Entitlements::for($user->tenant)->feature('assistant')) {
                $this->fail($phone);

                return null;
            }

            $owner = User::query()->where('whatsapp_phone', $phone)->first();
            if ($owner !== null && $owner->getKey() !== $user->getKey()) {
                $this->fail($phone); // o número já é de outra pessoa

                return null;
            }

            $user->forceFill(['whatsapp_phone' => $phone, 'whatsapp_verified_at' => now()])->save(); // troca de número solta o anterior
            $match->forceFill(['used_at' => now()])->save();
            WhatsAppLinkCode::query()->where('user_id', $user->getKey())->whereNull('used_at')->delete();
            WhatsAppLinkAttempt::query()->whereKey($phone)->delete();

            return $user->fresh();
        });
    }

    /** Usuário dono do número, só se ele foi verificado. */
    public function userFor(string $fromPhone): ?User
    {
        $phone = $this->normalize($fromPhone);

        return $phone === '' ? null : User::query()->where('whatsapp_phone', $phone)->whereNotNull('whatsapp_verified_at')->whereNull('deactivated_at')->whereNull('invite_token_hash')->first();
    }

    public function unlink(User $user): void
    {
        $user->forceFill(['whatsapp_phone' => null, 'whatsapp_verified_at' => null])->save();
        WhatsAppLinkCode::query()->where('user_id', $user->getKey())->delete();
    }

    public function isBlocked(string $fromPhone): bool
    {
        $row = WhatsAppLinkAttempt::query()->find($this->normalize($fromPhone));

        return $row?->blocked_until !== null && $row->blocked_until->isFuture();
    }

    /** Só dígitos (formato E.164 sem "+"). */
    public function normalize(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }

    /** Conta uma tentativa errada de forma atômica (duas mensagens ao mesmo tempo não colidem) e bloqueia no 5º erro. */
    private function fail(string $phone): void
    {
        DB::statement('UPDATE whatsapp_link_attempts SET attempts = 0, blocked_until = NULL WHERE phone = ? AND blocked_until IS NOT NULL AND blocked_until < ?', [$phone, now()]);
        DB::statement('INSERT INTO whatsapp_link_attempts (phone, attempts, created_at, updated_at) VALUES (?, 1, ?, ?) ON CONFLICT (phone) DO UPDATE SET attempts = whatsapp_link_attempts.attempts + 1, updated_at = EXCLUDED.updated_at', [$phone, now(), now()]);
        DB::statement('UPDATE whatsapp_link_attempts SET blocked_until = ? WHERE phone = ? AND attempts >= ? AND blocked_until IS NULL', [now()->addMinutes(self::BLOCK_MINUTES), $phone, self::MAX_ATTEMPTS]);
    }

    /** O texto é o código? Aceita hífen, espaço, minúsculas e uma frase em volta; só valores no alfabeto do código. */
    private function extract(string $text): ?string
    {
        $up = strtoupper($text);
        $whole = preg_replace('/[^A-Z0-9]/', '', $up) ?? '';
        if ($this->valid($whole)) {
            return $whole;
        }
        if (preg_match('/(?<![A-Z0-9])([A-Z0-9]{4})[-\s]?([A-Z0-9]{4})(?![A-Z0-9])/', $up, $m) && $this->valid($m[1].$m[2])) {
            return $m[1].$m[2];
        }
        foreach (preg_split('/[^A-Z0-9]+/', $up, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if ($this->valid($word)) {
                return $word;
            }
        }

        return null;
    }

    private function valid(string $candidate): bool
    {
        return (bool) preg_match('/^['.self::ALPHABET.']{8}$/', $candidate) && preg_match('/[A-Z]/', $candidate) === 1 && preg_match('/[2-9]/', $candidate) === 1;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
