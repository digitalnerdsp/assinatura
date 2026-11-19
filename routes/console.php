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

// Avisos de vencimento de contratos: 11:00 UTC = 08:00 em Brasília.
Artisan::command('contracts:send-alerts', function (\App\Contracts\ContractAlerts $alerts) {
    $this->info($alerts->send().' aviso(s) de vencimento enviado(s).');
})->purpose('Envia por e-mail os avisos de vencimento de contratos');
Schedule::command('contracts:send-alerts')->dailyAt('11:00')->timezone('UTC')->withoutOverlapping();

// Assistente no WhatsApp: apaga conversas e mensagens recebidas com mais de 90 dias (não toca no registro financeiro).
Artisan::command('assistant:prune', function (\App\Assistant\AssistantPruner $pruner) {
    $r = $pruner->run();
    $this->info("{$r['conversations']} conversa(s), {$r['inbounds']} mensagem(ns) recebida(s) e {$r['codes']} código(s) removido(s).");
})->purpose('Apaga dados de conversa do assistente com mais de 90 dias');
Schedule::command('assistant:prune')->dailyAt('04:10')->withoutOverlapping();

// Reenvia para a fila mensagens recebidas cujo despacho se perdeu (nunca começaram a ser tratadas).
Artisan::command('assistant:redispatch', function () {
    $n = 0;
    \App\Models\WhatsAppInbound::query()->whereNull('processed_at')->whereNull('processing_started_at')
        ->where('created_at', '<', now()->subMinutes(2))->where('created_at', '>', now()->subHours(6))
        ->each(function ($row) use (&$n) {
            \App\Jobs\ProcessInboundWhatsApp::dispatch($row->getKey());
            $n++;
        });
    $this->info("{$n} mensagem(ns) reenviada(s) para a fila.");
})->purpose('Reenvia para a fila mensagens do WhatsApp que não chegaram a ser tratadas');
Schedule::command('assistant:redispatch')->everyFiveMinutes()->withoutOverlapping();

// Cobra os avisos de preço da Meta que esperavam o preço da categoria.
Artisan::command('assistant:reconcile', function (\App\Assistant\Channel\StatusReconciler $reconciler) {
    $this->info($reconciler->run().' aviso(s) de preço conciliado(s).');
})->purpose('Concilia os avisos de preço de mensagens da Meta');
Schedule::command('assistant:reconcile')->hourly()->withoutOverlapping();

// Avisa no WhatsApp quem criou documentos parados há X dias (12:00 UTC = 09:00 em Brasília).
Artisan::command('documents:notify-stalled', function (\App\Assistant\Notifications\StalledDocuments $stalled) {
    $this->info($stalled->run().' aviso(s) de documento parado enfileirado(s).');
})->purpose('Avisa no WhatsApp sobre documentos parados');
Schedule::command('documents:notify-stalled')->dailyAt('12:00')->timezone('UTC')->withoutOverlapping();
