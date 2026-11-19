<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Assistant\Metering\MetaUsageLedger;
use App\Assistant\Metering\TokenWallet;
use App\Assistant\ToolGroups;
use App\Http\Controllers\Controller;
use App\Models\AssistantTokenEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Painel do assistente da empresa (só o dono): liga/desliga, quem usa, ações permitidas e consumo do mês. */
final class AssistantController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    public function __construct(private readonly TokenWallet $wallet, private readonly MetaUsageLedger $meta)
    {
    }

    public function index(Request $request): View
    {
        $tenant = $request->user()->tenant;
        $this->wallet->ensurePlanPackage($tenant);
        $start = CarbonImmutable::now(self::TZ)->startOfMonth()->utc();
        $period = CarbonImmutable::now(self::TZ)->format('Y-m');

        return view('assistant.index', [
            'tenant' => $tenant,
            'groups' => ToolGroups::GROUPS,
            'enabledGroups' => ToolGroups::enabled($tenant),
            'users' => User::query()->where('tenant_id', $tenant->getKey())->whereNull('deactivated_at')->orderBy('name')->get(),
            'balance' => $this->wallet->balance($tenant),
            'usedTokens' => -(int) AssistantTokenEntry::query()->where('tenant_id', $tenant->getKey())->where('kind', 'usage')->where('created_at', '>=', $start)->sum('tokens'),
            'metaTotal' => $this->meta->monthTotal($tenant, $period),
            'currency' => app(\App\Assistant\AssistantSettings::class)->currency(),
            'markup' => app(\App\Assistant\AssistantSettings::class)->markupPercent(),
            'period' => $period,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['string', Rule::in(array_keys(ToolGroups::GROUPS))],
            'users' => ['nullable', 'array'],
            'users.*' => ['integer'],
        ], ['groups.*.in' => 'Ação desconhecida.']);

        $groups = array_values(array_unique((array) ($data['groups'] ?? [])));
        $tenant->forceFill([
            'assistant_enabled' => (bool) $data['enabled'],
            'assistant_tools' => count($groups) === count(ToolGroups::GROUPS) ? null : $groups, // todas = sem restrição
        ])->save();

        if ($request->has('users') || $request->boolean('users_present')) {
            $allowed = array_map('intval', (array) ($data['users'] ?? []));
            // só usuários desta empresa: um id de outra empresa simplesmente não casa
            User::query()->where('tenant_id', $tenant->getKey())->update(['assistant_enabled' => false]);
            User::query()->where('tenant_id', $tenant->getKey())->whereIn('id', $allowed)->update(['assistant_enabled' => true]);
        }

        return redirect()->route('assistant.index')->with('status', 'Configurações do assistente salvas.');
    }
}
