<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Assistant\Pricing\AssistantPricing;
use App\Assistant\Pricing\TokenEconomics;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/** Economia dos tokens (só super administrador): preços por modelo, câmbio, margem, o valor de um token e o uso real do mês. */
final class TokenEconomyController extends Controller
{
    public function edit(Request $request, AssistantPricing $pricing, TokenEconomics $economics): View
    {
        $mes = (string) $request->query('mes', '');
        $period = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1 ? $mes : CarbonImmutable::now('America/Sao_Paulo')->format('Y-m');

        $configured = $pricing->configured();
        $defaults = $pricing->defaults();
        $rows = [];
        foreach ($configured ? $pricing->models() : array_keys($defaults['prices']) as $model) {
            $p = $configured ? $pricing->price($model) : $defaults['prices'][$model];
            $rows[] = ['model' => $model, 'input' => $p['input'] ?? null, 'cached' => $p['cached'] ?? null, 'output' => $p['output'] ?? null, 'since' => $configured ? ($pricing->versions($model)[count($pricing->versions($model)) - 1]['since'] ?? null) : null];
        }

        return view('admin.economy', [
            'configured' => $configured,
            'rows' => $rows,
            'baseModel' => $configured ? $pricing->baseModel() : $defaults['base_model'],
            'fx' => $configured ? $pricing->fx() : $defaults['brl_per_usd'],
            'fxAsOf' => $pricing->fxAsOf(),
            'margin' => $configured ? $pricing->marginPercent() : $defaults['margin_percent'],
            'fee' => $configured ? $pricing->feePercent() : $defaults['fee_percent'],
            'perMillion' => $pricing->perMillion(),
            'alertPercent' => $pricing->franchiseAlertPercent(),
            'stats' => $economics->conversationStats(30),
            'perConversation' => $economics->tokensPerConversation(),
            'period' => $period,
            'report' => $economics->monthReport($period),
        ]);
    }

    public function update(Request $request, AssistantPricing $pricing): RedirectResponse
    {
        $in = $request->validate([
            'base_model' => ['required', 'string', 'max:80'],
            'brl_per_usd' => ['required', 'string', 'max:20'], 'margin_percent' => ['required', 'string', 'max:20'], 'fee_percent' => ['required', 'string', 'max:20'],
            'franchise_alert_percent' => ['nullable', 'numeric', 'between:1,100'],
            'prices' => ['nullable', 'array', 'max:30'],
            'prices.*.model' => ['nullable', 'string', 'max:80'], 'prices.*.input' => ['nullable', 'string', 'max:20'],
            'prices.*.cached' => ['nullable', 'string', 'max:20'], 'prices.*.output' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $pricing->save($in + ['prices' => []]);
            if (isset($in['franchise_alert_percent'])) {
                \App\Models\Setting::put('assistant.franchise_alert_percent', (string) $in['franchise_alert_percent']);
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['economy' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.economy')->with('status', 'Tabela de preços salva. A fórmula já vale para as próximas respostas.');
    }
}
