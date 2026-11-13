<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\EnvelopeStatus;
use App\Folders\FolderService;
use App\Models\DocumentTemplate;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Tag;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Números dos documentos da empresa (sem a lixeira): situação, tempo médio do envio até a conclusão e a
 * distribuição por faixas. Filtros: período de criação, pasta (com subpastas), etiqueta, modelo e signatário.
 * Ids de outra empresa nunca casam: o resultado fica vazio.
 */
final class ReportService
{
    /** Faixa => limite superior em segundos (null = sem limite). */
    public const BUCKETS = ['30m' => 1800, '1h' => 3600, '24h' => 86400, '2d' => 172800, '5d' => 432000, '10d' => 864000, 'more' => null];

    public const BUCKET_LABELS = ['30m' => '< 30 min', '1h' => '< 1 h', '24h' => '< 24 h', '2d' => '< 2 dias', '5d' => '< 5 dias', '10d' => '< 10 dias', 'more' => '10 dias ou mais'];

    private const TZ = 'America/Sao_Paulo';

    /**
     * @param  array<string,mixed>  $filters
     * @return array{total:int,completed:int,in_progress:int,refused:int,expired:int,cancelled:int,draft:int,avg_seconds:?int,buckets:array<string,int>}
     */
    public function summary(Tenant $tenant, array $filters): array
    {
        $base = $this->base($tenant, $filters);
        $byStatus = (clone $base)->reorder()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $n = fn (EnvelopeStatus ...$s) => (int) collect($s)->sum(fn ($x) => $byStatus[$x->value] ?? 0);

        $durations = (clone $base)->reorder()->where('status', EnvelopeStatus::Completed->value)
            ->whereNotNull('sent_at')->whereNotNull('completed_at')
            ->selectRaw('extract(epoch from (completed_at - sent_at)) as s');

        $cases = [];
        $lower = 0;
        foreach (self::BUCKETS as $key => $upper) {
            $cond = $upper === null ? "s >= {$lower}" : "s >= {$lower} AND s < {$upper}";
            $cases[] = "sum(case when {$cond} then 1 else 0 end) as b_{$key}";
            $lower = $upper ?? $lower;
        }
        $agg = DB::query()->fromSub($durations, 'd')->selectRaw('avg(s) as avg_s, '.implode(', ', $cases))->first();

        $buckets = [];
        foreach (array_keys(self::BUCKETS) as $key) {
            $buckets[$key] = (int) ($agg->{'b_'.$key} ?? 0);
        }

        return [
            'total' => (int) $byStatus->sum(),
            'completed' => $n(EnvelopeStatus::Completed),
            'in_progress' => $n(EnvelopeStatus::Sent, EnvelopeStatus::InProgress, EnvelopeStatus::Sealing),
            'refused' => $n(EnvelopeStatus::Refused),
            'expired' => $n(EnvelopeStatus::Expired),
            'cancelled' => $n(EnvelopeStatus::Cancelled),
            'draft' => $n(EnvelopeStatus::Draft),
            'avg_seconds' => $agg && $agg->avg_s !== null ? (int) round((float) $agg->avg_s) : null,
            'buckets' => $buckets,
        ];
    }

    /** Linhas detalhadas (mais recentes primeiro). @param array<string,mixed> $filters */
    public function rows(Tenant $tenant, array $filters): Builder
    {
        return $this->base($tenant, $filters)->with(['folder', 'tags', 'signers'])->latest();
    }

    /** @param array<string,mixed> $filters */
    private function base(Tenant $tenant, array $filters): Builder
    {
        $tenantId = $tenant->getKey();
        $none = fn (Builder $q) => $q->whereRaw('1 = 0');

        $query = Envelope::query()->where('tenant_id', $tenantId)->whereNull('trashed_at');

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], self::TZ)->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse($filters['to'], self::TZ)->endOfDay()->utc());
        }
        if (! empty($filters['folder'])) {
            $folder = Str::isUuid($filters['folder']) ? Folder::query()->where('tenant_id', $tenantId)->find($filters['folder']) : null;
            $folder ? $query->whereIn('folder_id', app(FolderService::class)->descendantIds($folder)) : $none($query);
        }
        if (! empty($filters['tag'])) {
            $ok = Str::isUuid($filters['tag']) && Tag::query()->where('tenant_id', $tenantId)->whereKey($filters['tag'])->exists();
            $ok ? $query->whereHas('tags', fn ($t) => $t->where('tags.id', $filters['tag'])) : $none($query);
        }
        if (! empty($filters['template'])) {
            $ok = Str::isUuid($filters['template']) && DocumentTemplate::query()->where('tenant_id', $tenantId)->whereKey($filters['template'])->exists();
            $ok ? $query->where('template_id', $filters['template']) : $none($query);
        }
        if (! empty($filters['signer'])) {
            $email = mb_strtolower(trim((string) $filters['signer']));
            $query->whereHas('signers', fn ($s) => $s->whereRaw('lower(email) = ?', [$email]));
        }

        return $query;
    }

    /** "45 min", "3 h 10 min", "2 dias 4 h". */
    public static function humanDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        if ($seconds < 3600) {
            return max(1, (int) round($seconds / 60)).' min';
        }
        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);
            $m = (int) round(($seconds % 3600) / 60);

            return $h.' h'.($m > 0 ? ' '.$m.' min' : '');
        }
        $d = intdiv($seconds, 86400);
        $h = (int) round(($seconds % 86400) / 3600);

        return $d.' '.($d === 1 ? 'dia' : 'dias').($h > 0 ? ' '.$h.' h' : '');
    }
}
