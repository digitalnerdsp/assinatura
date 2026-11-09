<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Configuração de e-mail (SMTP) editável no painel admin. Quando salva, tem precedência
 * sobre as variáveis MAIL_* do ambiente. A senha fica cifrada com a APP_KEY.
 */
final class MailSettings
{
    private const KEY = 'mail';
    private const CACHE_KEY = 'mail.settings';

    /** @return array{host:string,port:int,username:string,password:string,encryption:string,from_address:string,from_name:string}|null */
    public static function load(): ?array
    {
        $raw = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::get(self::KEY) ?? '');

        if ($raw === '' || $raw === null) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || empty($data['host'])) {
            return null;
        }

        try {
            $data['password'] = ($data['password'] ?? '') === '' ? '' : Crypt::decryptString($data['password']);
        } catch (DecryptException) {
            $data['password'] = ''; // APP_KEY mudou: peça para salvar de novo
        }

        return $data + ['username' => '', 'encryption' => 'tls', 'from_name' => config('app.name')];
    }

    /** Aplica a configuração salva à configuração do Laravel (chamado no boot). */
    public static function apply(): void
    {
        $c = self::load();
        if ($c === null) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $c['host'],
            'mail.mailers.smtp.port' => (int) $c['port'],
            'mail.mailers.smtp.username' => $c['username'] !== '' ? $c['username'] : null,
            'mail.mailers.smtp.password' => $c['password'] !== '' ? $c['password'] : null,
            // Symfony Mailer: "smtps" = TLS implícito (465); "smtp" negocia STARTTLS quando disponível.
            'mail.mailers.smtp.scheme' => $c['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => $c['from_address'],
            'mail.from.name' => $c['from_name'],
        ]);

        Mail::purge('smtp'); // descarta instância já criada com a configuração antiga
    }

    /** @param array{host:string,port:int,username:?string,password:?string,encryption:string,from_address:string,from_name:string} $data */
    public static function save(array $data): void
    {
        $previous = self::load();
        $password = $data['password'] ?? null;

        // Campo de senha em branco = manter a senha atual.
        if ($password === null || $password === '') {
            $password = $previous['password'] ?? '';
        }

        Setting::put(self::KEY, json_encode([
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'username' => $data['username'] ?? '',
            'password' => $password === '' ? '' : Crypt::encryptString($password),
            'encryption' => $data['encryption'],
            'from_address' => $data['from_address'],
            'from_name' => $data['from_name'],
        ], JSON_THROW_ON_ERROR));

        Cache::forget(self::CACHE_KEY);
        self::apply();
    }

    public static function clear(): void
    {
        Setting::put(self::KEY, null);
        Cache::forget(self::CACHE_KEY);
    }
}
