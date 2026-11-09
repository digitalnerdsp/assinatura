<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\EnvelopeStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\User;
use App\Support\MonthlySeries;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(): View
    {
        $monthStart = now()->startOfMonth();

        $byStatus = Envelope::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        $topTenants = Envelope::query()
            ->join('tenants', 'tenants.id', '=', 'envelopes.tenant_id')
            ->where('envelopes.created_at', '>=', $monthStart)
            ->groupBy('tenants.id', 'tenants.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get(['tenants.id', 'tenants.name', DB::raw('count(*) as total')]);

        $created = MonthlySeries::counts(Envelope::query(), 'created_at');

        return view('admin.dashboard', [
            'tenants' => Tenant::count(),
            'tenantsSuspended' => Tenant::whereNotNull('suspended_at')->count(),
            'tenantsNew30' => Tenant::where('created_at', '>=', now()->subDays(30))->count(),
            'users' => User::count(),
            'envelopes' => Envelope::count(),
            'envelopesMonth' => Envelope::where('created_at', '>=', $monthStart)->count(),
            'envelopesDelta' => MonthlySeries::delta($created),
            'completedMonth' => Envelope::where('completed_at', '>=', $monthStart)->count(),
            'storageBytes' => (int) Document::sum('size'),
            'topTenants' => $topTenants,
            'recentTenants' => Tenant::query()->latest()->limit(6)->get(),
            'labels' => MonthlySeries::labels(),
            'created' => $created,
            'completed' => MonthlySeries::counts(Envelope::query(), 'completed_at'),
            'newTenants' => MonthlySeries::counts(Tenant::query(), 'created_at'),
            'statusChart' => collect(EnvelopeStatus::cases())
                ->map(fn ($s) => ['label' => __('status.'.$s->value), 'value' => (int) ($byStatus[$s->value] ?? 0)])
                ->filter(fn ($x) => $x['value'] > 0)->values(),
        ]);
    }
}
