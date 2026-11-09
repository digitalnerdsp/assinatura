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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SMTP salvo no painel admin tem precedência sobre MAIL_* do ambiente.
        // Falha silenciosa: no primeiro boot (antes das migrations) a tabela ainda não existe.
        try {
            \App\Support\MailSettings::apply();
        } catch (\Throwable) {
        }
    }
}
