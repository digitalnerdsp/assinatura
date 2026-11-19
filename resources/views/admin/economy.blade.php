@extends('layouts.app')
@section('title', 'Economia dos tokens')
@section('section', 'Plataforma')
@section('content')
@php
    $n = function ($v) {
        if ($v === null || $v === '') {
            return '';
        }
        $s = rtrim(number_format((float) $v, 6, ',', ''), '0');
        if (preg_match('/,\d$/', $s) === 1) {
            $s .= '0';
        }

        return rtrim($s, ',');
    };
    $m = fn ($v, $d = 2) => number_format((float) $v, $d, ',', '.');
    $i = fn ($v) => number_format((int) $v, 0, ',', '.');
    $blank = ['model' => '', 'input' => null, 'cached' => null, 'output' => null, 'since' => null];
    $all = array_merge($rows, [$blank, $blank]);
@endphp
@if (session('status'))<div class="alert alert-success border-0">{{ session('status') }}</div>@endif
@error('economy')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror

@unless ($configured)
    <div class="alert alert-warning border-0">
        A fórmula <strong>ainda não está ligada</strong>: hoje a carteira usa os pesos manuais antigos. Os valores abaixo são os iniciais (informados por você): <strong>confirme-os na tabela de preços da sua conta da OpenAI</strong> e salve para ligar a fórmula.
    </div>
@endunless

<form method="post" action="{{ route('admin.economy.update') }}" class="row">
    @csrf @method('PUT')
    <div class="col-12 col-xl-7">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-dollar-circle me-1"></i> Preço por modelo (dólar por 1 milhão de tokens)</h6></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Modelo</th><th>Entrada</th><th>Entrada em cache</th><th>Saída</th></tr></thead>
                        <tbody>
                        @foreach ($all as $k => $r)
                            <tr>
                                <td><input class="form-control form-control-sm" name="prices[{{ $k }}][model]" value="{{ old("prices.$k.model", $r['model']) }}" placeholder="identificador do modelo" aria-label="Modelo"></td>
                                <td><input class="form-control form-control-sm" name="prices[{{ $k }}][input]" inputmode="decimal" value="{{ old("prices.$k.input", $n($r['input'])) }}" aria-label="Entrada"></td>
                                <td><input class="form-control form-control-sm" name="prices[{{ $k }}][cached]" inputmode="decimal" value="{{ old("prices.$k.cached", $n($r['cached'])) }}" placeholder="opcional" aria-label="Entrada em cache"></td>
                                <td><input class="form-control form-control-sm" name="prices[{{ $k }}][output]" inputmode="decimal" value="{{ old("prices.$k.output", $n($r['output'])) }}" aria-label="Saída"></td>
                            </tr>
                            @if ($r['since'])<tr><td colspan="4" class="text-secondary small pt-0 border-0">preço vigente desde {{ \Carbon\Carbon::parse($r['since'])->format('d/m/Y') }}</td></tr>@endif
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-secondary small mb-0">O raciocínio do modelo conta como saída. Entrada em cache em branco = cobrada como entrada cheia. Mudar um preço cria uma versão nova; o custo já gravado nunca muda.</p>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-calculator me-1"></i> Câmbio, margem e modelo base</h6></div>
            <div class="card-body row g-3">
                <div class="col-12"><label class="form-label" for="e-base">Modelo base (a régua do token equivalente)</label><input id="e-base" class="form-control" name="base_model" value="{{ old('base_model', $baseModel) }}"><div class="form-text">1 token equivalente = 1 token de entrada, sem cache, deste modelo.</div></div>
                <div class="col-md-4"><label class="form-label" for="e-fx">Câmbio (R$ por US$)</label><input id="e-fx" class="form-control" name="brl_per_usd" inputmode="decimal" value="{{ old('brl_per_usd', $n($fx)) }}">@if ($fxAsOf)<div class="form-text">atualizado em {{ \Carbon\Carbon::parse($fxAsOf)->format('d/m/Y') }}</div>@endif</div>
                <div class="col-md-4"><label class="form-label" for="e-margin">Margem (%)</label><input id="e-margin" class="form-control" name="margin_percent" inputmode="decimal" value="{{ old('margin_percent', $n($margin)) }}"></div>
                <div class="col-md-4"><label class="form-label" for="e-fee">Taxas (%)</label><input id="e-fee" class="form-control" name="fee_percent" inputmode="decimal" value="{{ old('fee_percent', $n($fee)) }}"><div class="form-text">gateway, impostos</div></div>
                <div class="col-12"><label class="form-label" for="e-alert">Alertar no plano quando a franquia custar mais que (% da mensalidade)</label><input id="e-alert" class="form-control" name="franchise_alert_percent" inputmode="decimal" value="{{ old('franchise_alert_percent', $n($alertPercent)) }}">@error('franchise_alert_percent')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-12"><button class="btn btn-primary">Salvar</button></div>
            </div>
        </div>
    </div>
</form>

<div class="row">
    <div class="col-12 col-xl-6">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-coin-stack me-1"></i> Quanto vale um token</h6></div>
            <div class="card-body">
                @if ($perMillion)
                    <table class="table table-sm mb-2">
                        <thead><tr><th></th><th class="text-end">Custo</th><th class="text-end">Venda</th></tr></thead>
                        <tbody>
                        <tr><td>1 milhão de tokens equivalentes</td><td class="text-end">R$ {{ $m($perMillion['cost_brl']) }}</td><td class="text-end">R$ {{ $m($perMillion['sale_brl']) }}</td></tr>
                        <tr><td>1.000 tokens equivalentes</td><td class="text-end">R$ {{ $m($perMillion['cost_brl'] / 1000, 5) }}</td><td class="text-end">R$ {{ $m($perMillion['sale_brl'] / 1000, 5) }}</td></tr>
                        </tbody>
                    </table>
                    <p class="text-secondary small mb-0">Venda = custo × (1 + margem) × (1 + taxas). É o piso: o preço de cada pacote você define na tela de pacotes, e pode ser maior pelo valor que o cliente percebe.</p>
                @else
                    <p class="text-secondary mb-0">Salve a tabela de preços para ver o valor de um token.</p>
                @endif
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-chat me-1"></i> Conversa média (últimos 30 dias)</h6></div>
            <div class="card-body">
                <div class="mb-1">{{ $stats['conversations'] }} conversa(s) medida(s), {{ $i($stats['eq_tokens']) }} tokens equivalentes.</div>
                <div class="mb-1">Tokens por conversa: <strong>{{ $i($perConversation['value']) }}</strong> {{ $perConversation['measured'] ? '(média medida)' : '(estimativa: poucas conversas ainda)' }}</div>
                @if ($stats['avg_cost_brl'] !== null)<div>Custo real por conversa: <strong>R$ {{ $m($stats['avg_cost_brl'], 5) }}</strong></div>@endif
            </div>
        </div>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="mb-0"><i class="bx bx-bar-chart-alt-2 me-1"></i> Uso do mês por empresa</h6>
        <form method="get" action="{{ route('admin.economy') }}" class="d-flex gap-2 align-items-center">
            <label class="visually-hidden" for="r-mes">Mês</label><input id="r-mes" class="form-control form-control-sm" type="month" name="mes" value="{{ $period }}">
            <button class="btn btn-outline-secondary btn-sm">Ver</button>
        </form>
    </div>
    <div class="card-body">
        @if ($report === [])
            <p class="text-secondary mb-0">Nenhum uso de IA neste mês.</p>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Empresa</th><th class="text-end">Tokens equivalentes</th><th class="text-end">Custo real (IA)</th><th class="text-end">Valor de venda equivalente</th></tr></thead>
                    <tbody>
                    @foreach ($report as $r)
                        <tr><td>{{ $r['tenant'] }}</td><td class="text-end">{{ $i($r['eq_tokens']) }}</td><td class="text-end">R$ {{ $m($r['cost_brl'], 4) }}</td><td class="text-end">{{ $r['sale_brl'] === null ? '—' : 'R$ '.$m($r['sale_brl'], 4) }}</td></tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end">{{ $i(array_sum(array_column($report, 'eq_tokens'))) }}</td><td class="text-end">R$ {{ $m(array_sum(array_column($report, 'cost_brl')), 4) }}</td><td class="text-end">{{ $perMillion ? 'R$ '.$m(array_sum(array_column($report, 'sale_brl')), 4) : '—' }}</td></tr></tfoot>
                </table>
            </div>
            <p class="text-secondary small mt-2 mb-0">Custo real = o que a OpenAI cobra, calculado com o preço e o câmbio do dia de cada resposta. Mensagens da Meta têm repasse separado.</p>
        @endif
    </div>
</div>
@endsection
