<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Messaging\MessageSender::class,
            fn () => config('messaging.driver') === 'twilio' ? new \App\Messaging\TwilioSender() : new \App\Messaging\LogSender(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Atrás do proxy do Coolify (TLS termina fora do container): links sempre em https quando o APP_URL for https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // SMTP salvo no painel admin tem precedência sobre MAIL_* do ambiente.
        // Falha silenciosa: no primeiro boot (antes das migrations) a tabela ainda não existe.
        try {
            \App\Support\MailSettings::apply();
        } catch (\Throwable) {
        }
    }
}
