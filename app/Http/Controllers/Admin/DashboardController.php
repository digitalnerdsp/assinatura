<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\User;
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

        return view('admin.dashboard', [
            'tenants' => Tenant::count(),
            'tenantsSuspended' => Tenant::whereNotNull('suspended_at')->count(),
            'tenantsNew30' => Tenant::where('created_at', '>=', now()->subDays(30))->count(),
            'users' => User::count(),
            'envelopes' => Envelope::count(),
            'envelopesMonth' => Envelope::where('created_at', '>=', $monthStart)->count(),
            'completedMonth' => Envelope::where('completed_at', '>=', $monthStart)->count(),
            'byStatus' => $byStatus,
            'storageBytes' => (int) Document::sum('size'),
            'topTenants' => $topTenants,
        ]);
    }
}
