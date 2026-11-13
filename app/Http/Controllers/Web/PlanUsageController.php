<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\UsageEntry;
use App\Plans\Entitlements;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Configurações › Plano e uso: o plano da empresa, quanto já usou e o extrato de documentos. Só o responsável. */
final class PlanUsageController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->role === 'owner', 403);
        $tenant = $user->tenant;
        $ent = Entitlements::for($tenant);

        return view('plan-usage.index', [
            'tenant' => $tenant,
            'sub' => $ent->subscription()->load('plan'),
            'usage' => $ent->usage(),
            'rules' => $ent->rules(),
            'features' => array_filter(Plan::FEATURES, fn ($k) => $ent->feature($k)),
            'entries' => UsageEntry::query()->where('tenant_id', $tenant->getKey())->latest('occurred_at')->limit(30)->get(),
        ]);
    }
}
