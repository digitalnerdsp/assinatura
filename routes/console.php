<?php

use App\Envelopes\LifecycleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('envelopes:expire', function (LifecycleService $lifecycle) {
    $this->info($lifecycle->expireDue().' envelope(s) expirado(s).');
})->purpose('Marca como expirados os envelopes cujo prazo passou');

Artisan::command('envelopes:remind', function (LifecycleService $lifecycle) {
    $this->info($lifecycle->sendReminders().' lembrete(s) enviado(s).');
})->purpose('Envia lembretes a quem ainda não assinou');

// Requer o agendador rodando (serviço "scheduler" no docker-compose: php artisan schedule:work).
// Sinal de vida para o painel de saúde.
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put(\App\Support\SystemHealth::HEARTBEAT_KEY, now()->toIso8601String(), 3600))->everyMinute()->name('heartbeat');
Schedule::command('envelopes:expire')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('envelopes:remind')->hourly()->withoutOverlapping();

Artisan::command('api:prune-idempotency', function () {
    $n = \Illuminate\Support\Facades\DB::table('api_idempotency_keys')->where('created_at', '<', now()->subDay())->delete();
    $this->info("{$n} chave(s) de idempotência removida(s).");
})->purpose('Remove chaves de idempotência com mais de 24 h');
Schedule::command('api:prune-idempotency')->daily();
