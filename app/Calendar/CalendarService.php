<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Eventos do calendário da empresa (sem a lixeira), em datas de São Paulo:
 * criado, concluido, prazo (só documento aberto) e lembrete (próximo lembrete previsto).
 */
final class CalendarService
{
    public const TZ = 'America/Sao_Paulo';

    public const TYPES = ['prazo' => 'Prazo', 'lembrete' => 'Lembrete', 'criado' => 'Criado', 'concluido' => 'Concluído'];

    /** @return Collection<int, array{date:string,type:string,envelope_id:string,title:string}> */
    public function events(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $start = $from->setTimezone(self::TZ)->startOfDay();
        $end = $to->setTimezone(self::TZ)->endOfDay();
        $startUtc = $start->utc();
        $endUtc = $end->utc();
        $base = fn () => Envelope::query()->where('tenant_id', $tenant->getKey())->whereNull('trashed_at');
        $open = [EnvelopeStatus::Sent->value, EnvelopeStatus::InProgress->value];
        $events = collect();
        $push = function (Envelope $e, string $type, CarbonInterface $at) use ($events, $start, $end): void {
            $local = CarbonImmutable::instance($at)->setTimezone(self::TZ);
            if ($local->betweenIncluded($start, $end)) {
                $events->push(['date' => $local->format('Y-m-d'), 'type' => $type, 'envelope_id' => $e->getKey(), 'title' => $e->title]);
            }
        };

        foreach ($base()->whereBetween('created_at', [$startUtc, $endUtc])->get(['id', 'title', 'created_at']) as $e) {
            $push($e, 'criado', $e->created_at);
        }
        foreach ($base()->where('status', EnvelopeStatus::Completed->value)->whereBetween('completed_at', [$startUtc, $endUtc])->get(['id', 'title', 'completed_at']) as $e) {
            $push($e, 'concluido', $e->completed_at);
        }
        foreach ($base()->whereIn('status', $open)->whereBetween('expires_at', [$startUtc, $endUtc])->get(['id', 'title', 'expires_at']) as $e) {
            $push($e, 'prazo', $e->expires_at);
        }

        $max = (int) config('signing.max_reminders');
        $base()->whereIn('status', $open)->whereNotNull('reminder_every_days')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->each(function (Envelope $e) use ($push, $max): void {
                $next = $e->activeSigners()
                    ->filter(fn ($s) => ($s->last_reminded_at ?? $s->invited_at) !== null && $s->reminders_count < $max)
                    ->map(fn ($s) => CarbonImmutable::instance($s->last_reminded_at ?? $s->invited_at)->addDays($e->reminder_every_days))
                    ->min();
                if ($next !== null && ($e->expires_at === null || $next->lt($e->expires_at))) {
                    $push($e, 'lembrete', $next);
                }
            });

        $order = array_flip(array_keys(self::TYPES));

        return $events->sortBy(fn ($x) => $x['date'].$order[$x['type']].mb_strtolower($x['title']))->values();
    }
}
