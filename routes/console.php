<?php

use App\Envelopes\LifecycleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('documents:expire', function (LifecycleService $lifecycle) {
    $this->info($lifecycle->expireDue().' documento(s) expirado(s).');
})->purpose('Marca como expirados os documentos cujo prazo passou');

Artisan::command('documents:remind', function (LifecycleService $lifecycle) {
    $this->info($lifecycle->sendReminders().' lembrete(s) enviado(s).');
})->purpose('Envia lembretes a quem ainda não assinou');

// Requer o agendador rodando (serviço "scheduler" no docker-compose: php artisan schedule:work).
// Sinal de vida para o painel de saúde.
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put(\App\Support\SystemHealth::HEARTBEAT_KEY, now()->toIso8601String(), 3600))->everyMinute()->name('heartbeat');
Schedule::command('documents:expire')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('documents:remind')->hourly()->withoutOverlapping();

Artisan::command('documents:purge-trash', function (\App\Envelopes\TrashService $trash) {
    $this->info($trash->purge(30).' documento(s) removido(s) da lixeira.');
})->purpose('Apaga de vez os documentos que estão há 30 dias ou mais na lixeira');
Schedule::command('documents:purge-trash')->daily()->withoutOverlapping();
Schedule::command('documents:trash-stale-drafts')->daily()->withoutOverlapping();

Schedule::command('storage:reconcile')->dailyAt('03:30')->withoutOverlapping();

Artisan::command('api:prune-idempotency', function () {
    $n = \Illuminate\Support\Facades\DB::table('api_idempotency_keys')->where('created_at', '<', now()->subDay())->delete();
    $this->info("{$n} chave(s) de idempotência removida(s).");
})->purpose('Remove chaves de idempotência com mais de 24 h');
Schedule::command('api:prune-idempotency')->daily();
