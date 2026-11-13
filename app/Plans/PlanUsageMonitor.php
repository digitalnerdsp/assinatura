<?php

declare(strict_types=1);

namespace App\Plans;

use App\Mail\PlanUsageWarningMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** Avisa o responsável da empresa, por e-mail, uma vez ao chegar a 80% e uma vez a 100% de um limite do plano (por período). */
final class PlanUsageMonitor
{
    public function check(Tenant $tenant): void
    {
        try {
            $ent = Entitlements::for($tenant);
            $sub = $ent->subscription();
            $top = $ent->highest();
            if ($top === null) {
                return;
            }

            if ($top['pct'] >= 100 && $sub->warned_100_at === null) {
                $this->notify($tenant, $ent, true);
                $sub->forceFill(['warned_100_at' => now(), 'warned_80_at' => $sub->warned_80_at ?? now()])->save();
            } elseif ($top['pct'] >= 80 && $top['pct'] < 100 && $sub->warned_80_at === null) {
                $this->notify($tenant, $ent, false);
                $sub->forceFill(['warned_80_at' => now()])->save();
            }
        } catch (\Throwable $e) {
            report($e); // um aviso nunca derruba o envio
        }
    }

    private function notify(Tenant $tenant, Entitlements $ent, bool $full): void
    {
        $items = array_values(array_filter($ent->pressure(), fn ($m) => $m['pct'] >= ($full ? 100 : 80)));
        User::query()->where('tenant_id', $tenant->getKey())->where('role', 'owner')
            ->whereNull('deactivated_at')->whereNull('invite_token_hash')
            ->each(fn (User $u) => Mail::to($u->email, $u->name)->send(new PlanUsageWarningMail($tenant, $items, $full)));
    }
}
