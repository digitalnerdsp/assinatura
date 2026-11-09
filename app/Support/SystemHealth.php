<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\EvidenceEvent;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Signing\Exceptions\SigningException;
use App\Signing\Support\SealerClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Verificações e números para o painel de saúde do super admin. Cada verificação nunca lança. */
final class SystemHealth
{
    public const HEARTBEAT_KEY = 'scheduler:heartbeat';

    public function __construct(private readonly SealerClient $sealer)
    {
    }

    /** @return list<array{label:string,status:'ok'|'warn'|'fail',detail:string}> */
    public function checks(): array
    {
        return [
            $this->guard('Banco de dados', fn () => $this->database()),
            $this->guard('Serviço de selagem', fn () => $this->sealer()),
            $this->guard('Fila (worker)', fn () => $this->queue()),
            $this->guard('Agendador (lembretes e expiração)', fn () => $this->scheduler()),
            $this->guard('Selagens travadas', fn () => $this->stuckSealing()),
            $this->guard('Webhooks com falha (24h)', fn () => $this->webhooks()),
            $this->guard('Backup do banco', fn () => $this->backup()),
            $this->guard('Espaço em disco', fn () => $this->disk()),
        ];
    }

    /** @return array<string,mixed> */
    public function metrics(): array
    {
        $since = now()->subDay();

        return [
            'envelopes_by_status' => Envelope::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all(),
            'sealed_24h' => EvidenceEvent::query()->where('type', 'envelope_sealed')->where('occurred_at', '>=', $since)->count(),
            'seal_failed_24h' => EvidenceEvent::query()->where('type', 'seal_failed')->where('occurred_at', '>=', $since)->count(),
            'jobs_pending' => (int) DB::table('jobs')->count(),
            'jobs_failed' => (int) DB::table('failed_jobs')->count(),
            'db_size' => $this->dbSize(),
            'usage' => $this->usagePerTenant(),
            'recent_failures' => EvidenceEvent::query()->where('type', 'seal_failed')->latest('occurred_at')->limit(8)->get(['envelope_id', 'payload', 'occurred_at']),
        ];
    }

    /** Uso dos últimos 30 dias por empresa (maiores primeiro). @return list<array<string,mixed>> */
    public function usagePerTenant(int $limit = 10): array
    {
        $month = now()->subDays(30);

        $envelopes = Envelope::query()
            ->select('tenant_id', DB::raw('count(*) as total'), DB::raw("count(*) filter (where status = 'completed') as completed"), DB::raw("count(*) filter (where status = 'sealing') as sealing"))
            ->where('created_at', '>=', $month)->groupBy('tenant_id')->get()->keyBy('tenant_id');

        $storage = DB::table('documents')->select('tenant_id', DB::raw('coalesce(sum(size),0) as bytes'))->groupBy('tenant_id')->pluck('bytes', 'tenant_id');

        return Tenant::query()->get(['id', 'name', 'monthly_envelope_limit', 'suspended_at'])
            ->map(fn (Tenant $t) => [
                'id' => $t->getKey(),
                'name' => $t->name,
                'envelopes' => (int) ($envelopes[$t->getKey()]->total ?? 0),
                'completed' => (int) ($envelopes[$t->getKey()]->completed ?? 0),
                'sealing' => (int) ($envelopes[$t->getKey()]->sealing ?? 0),
                'storage_mb' => round(((int) ($storage[$t->getKey()] ?? 0)) / 1048576, 1),
                'limit' => $t->monthly_envelope_limit,
                'suspended' => $t->isSuspended(),
            ])
            ->sortByDesc('envelopes')->take($limit)->values()->all();
    }

    private function database(): array
    {
        DB::select('select 1');

        return $this->result('ok', 'Conectado ('.$this->dbSize().')');
    }

    private function sealer(): array
    {
        try {
            $this->sealer->health();
        } catch (SigningException $e) {
            return $this->result('fail', $e->getMessage());
        }

        return $this->result('ok', 'Responde em '.config('signing.sealer.url'));
    }

    private function queue(): array
    {
        $failed = (int) DB::table('failed_jobs')->count();
        $oldest = DB::table('jobs')->where('available_at', '<=', now()->getTimestamp())->min('available_at');
        $waiting = $oldest === null ? 0 : now()->getTimestamp() - (int) $oldest;

        if ($waiting > 600) {
            return $this->result('fail', 'Há trabalhos parados na fila há '.intdiv($waiting, 60).' min: o worker pode estar fora do ar.');
        }
        if ($failed > 0) {
            return $this->result('warn', "{$failed} trabalho(s) com falha definitiva (tabela failed_jobs).");
        }

        return $this->result('ok', (int) DB::table('jobs')->count().' na fila, nenhum falho.');
    }

    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        if ($beat === null) {
            return $this->result('warn', 'Nenhum sinal ainda. O serviço "scheduler" precisa estar rodando (php artisan schedule:work).');
        }

        $age = now()->diffInMinutes(\Illuminate\Support\Carbon::parse($beat), true);

        return $age > 5
            ? $this->result('fail', 'Último sinal há '.(int) $age.' min: o agendador parou.')
            : $this->result('ok', 'Ativo (último sinal há '.(int) $age.' min).');
    }

    private function stuckSealing(): array
    {
        $stuck = Envelope::query()->where('status', EnvelopeStatus::Sealing->value)->where('updated_at', '<', now()->subMinutes(15))->count();

        return $stuck > 0
            ? $this->result('warn', "{$stuck} envelope(s) aguardando selagem há mais de 15 min.")
            : $this->result('ok', 'Nenhum envelope preso em "Selando".');
    }

    private function webhooks(): array
    {
        $failed = WebhookDelivery::query()->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count();

        return $failed > 0
            ? $this->result('warn', "{$failed} entrega(s) de webhook falharam nas últimas 24h.")
            : $this->result('ok', 'Sem falhas recentes.');
    }

    private function backup(): array
    {
        $dir = (string) env('BACKUP_PATH', '/backups');
        $files = is_dir($dir) ? (glob(rtrim($dir, '/').'/db-*.sql.gz') ?: []) : [];

        if ($files === []) {
            return $this->result('warn', 'Nenhum backup encontrado em '.$dir.'. Confira o serviço "backup" no docker-compose.');
        }

        $latest = max(array_map('filemtime', $files));
        $hours = (time() - $latest) / 3600;
        $when = date('d/m/Y H:i', $latest);

        return $hours > 36
            ? $this->result('fail', "Último backup em {$when} (há ".(int) $hours.' h).')
            : $this->result('ok', "Último backup em {$when} (".count($files).' arquivo(s) guardados).');
    }

    private function disk(): array
    {
        $path = storage_path('app');
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return $this->result('warn', 'Não foi possível medir o disco.');
        }

        $pct = $free / $total * 100;
        $detail = round($free / 1073741824, 1).' GB livres ('.round($pct).'%)';

        return $pct < 10 ? $this->result('fail', $detail) : ($pct < 20 ? $this->result('warn', $detail) : $this->result('ok', $detail));
    }

    private function dbSize(): string
    {
        try {
            $bytes = (int) DB::scalar('select pg_database_size(current_database())');

            return round($bytes / 1048576, 1).' MB';
        } catch (Throwable) {
            return '—';
        }
    }

    /** @param callable():array{status:string,detail:string} $check */
    private function guard(string $label, callable $check): array
    {
        try {
            return ['label' => $label] + $check();
        } catch (Throwable $e) {
            return ['label' => $label] + $this->result('fail', $e->getMessage());
        }
    }

    /** @return array{status:string,detail:string} */
    private function result(string $status, string $detail): array
    {
        return ['status' => $status, 'detail' => $detail];
    }
}
