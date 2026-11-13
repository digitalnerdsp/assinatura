<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Calendar\CalendarService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Calendário de prazos, criações, conclusões e lembretes (mês ou semana). */
final class CalendarController extends Controller
{
    public function index(Request $request, CalendarService $calendar): View
    {
        $tz = CalendarService::TZ;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $view = $request->query('view') === 'week' ? 'week' : 'month';
        $raw = (string) $request->query('date', '');
        $date = $today;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $raw, $tz);
            if ($parsed !== false && $parsed->format('Y-m-d') === $raw && $parsed->year >= 2000 && $parsed->year <= 2100) {
                $date = $parsed;
            }
        }

        if ($view === 'week') {
            $from = $date->startOfWeek(CarbonImmutable::SUNDAY);
            $to = $from->addDays(6);
            $prev = $from->subWeek();
            $next = $from->addWeek();
            $title = $from->locale('pt_BR')->translatedFormat('j M').' – '.$to->locale('pt_BR')->translatedFormat('j M Y');
        } else {
            $from = $date->startOfMonth()->startOfWeek(CarbonImmutable::SUNDAY);
            $to = $date->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY)->startOfDay();
            $prev = $date->startOfMonth()->subMonth();
            $next = $date->startOfMonth()->addMonth();
            $title = $date->locale('pt_BR')->translatedFormat('F \d\e Y');
        }

        $byDay = $calendar->events($request->user()->tenant, $from, $to)->groupBy('date');
        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->format('Y-m-d');
            $days[] = [
                'date' => $d,
                'key' => $key,
                'in_range' => $view === 'week' || $d->month === $date->month,
                'today' => $d->equalTo($today),
                'events' => $byDay->get($key, collect())->all(),
            ];
        }

        return view('calendar.index', [
            'view' => $view,
            'title' => mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1),
            'days' => $days,
            'anchor' => $date->format('Y-m-d'),
            'prev' => $prev->format('Y-m-d'),
            'next' => $next->format('Y-m-d'),
            'types' => CalendarService::TYPES,
        ]);
    }
}
