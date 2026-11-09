<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\EnvelopeStatus;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Support\MonthlySeries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->user()->tenant;
        $base = Envelope::query()->where('tenant_id', $tenant->getKey());

        $created = MonthlySeries::counts($base, 'created_at');
        $completed = MonthlySeries::counts($base, 'completed_at');

        $byStatus = (clone $base)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $count = fn (EnvelopeStatus ...$s) => (int) collect($s)->sum(fn ($x) => $byStatus[$x->value] ?? 0);

        $pending = (clone $base)
            ->whereIn('status', [EnvelopeStatus::Sent->value, EnvelopeStatus::InProgress->value, EnvelopeStatus::Sealing->value])
            ->withCount(['signers', 'signers as signed_count' => fn ($q) => $q->where('status', 'signed')])
            ->latest()->limit(6)->get();

        $monthStart = now()->startOfMonth();
        $usedThisMonth = (clone $base)->where('sent_at', '>=', $monthStart)->count();

        return view('dashboard', [
            'tenant' => $tenant,
            'kpi' => [
                'month' => end($created),
                'monthDelta' => MonthlySeries::delta($created),
                'pending' => $count(EnvelopeStatus::Sent, EnvelopeStatus::InProgress, EnvelopeStatus::Sealing),
                'completedMonth' => end($completed),
                'completedDelta' => MonthlySeries::delta($completed),
                'failed' => $count(EnvelopeStatus::Refused, EnvelopeStatus::Expired, EnvelopeStatus::Cancelled),
                'total' => (int) $byStatus->sum(),
            ],
            'labels' => MonthlySeries::labels(),
            'created' => $created,
            'completed' => $completed,
            'statusChart' => collect(EnvelopeStatus::cases())
                ->map(fn ($s) => ['label' => __('status.'.$s->value), 'value' => (int) ($byStatus[$s->value] ?? 0)])
                ->filter(fn ($x) => $x['value'] > 0)->values(),
            'pending' => $pending,
            'recent' => (clone $base)->withCount('signers')->latest()->limit(8)->get(),
            'usedThisMonth' => $usedThisMonth,
        ]);
    }
}
