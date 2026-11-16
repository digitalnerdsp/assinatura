@extends('theme::layouts.app')
@section('title', 'Painel')
@section('section', 'Visão geral')
@section('native', '1')
@section('charts', '1')

@section('content')
{{-- Indicadores do mês --}}
<div class="ab-grid">
    <div class="card ab-md-6 ab-xl-3">
        <div class="card-body">
            <div class="ab-kpi">
                <span class="ab-kpi-icon tone-primary" aria-hidden="true"><i class="ti ti-send"></i></span>
                <div class="ab-kpi-body">
                    <p class="ab-kpi-label">Enviados no mês</p>
                    <p class="ab-kpi-value">{{ number_format((int) $kpi['month'], 0, ',', '.') }}</p>
                    <div class="ab-kpi-foot">
                        @if ($kpi['monthDelta'] === null)
                            <span>Sem base no mês anterior</span>
                        @else
                            <span class="ab-delta {{ $kpi['monthDelta'] > 0 ? 'up' : ($kpi['monthDelta'] < 0 ? 'down' : 'flat') }}">
                                <i class="ti ti-{{ $kpi['monthDelta'] > 0 ? 'arrow-up-right' : ($kpi['monthDelta'] < 0 ? 'arrow-down-right' : 'minus') }}" aria-hidden="true"></i>{{ $kpi['monthDelta'] > 0 ? '+' : ($kpi['monthDelta'] < 0 ? '−' : '') }}{{ str_replace('.', ',', (string) abs((float) $kpi['monthDelta'])) }}%
                            </span>
                            <span>vs. mês anterior</span>
                        @endif
                        <span>· {{ number_format((int) $kpi['total'], 0, ',', '.') }} no total</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card ab-md-6 ab-xl-3">
        <div class="card-body">
            <div class="ab-kpi">
                <span class="ab-kpi-icon tone-warning" aria-hidden="true"><i class="ti ti-clock"></i></span>
                <div class="ab-kpi-body">
                    <p class="ab-kpi-label">Aguardando assinatura</p>
                    <p class="ab-kpi-value">{{ number_format((int) $kpi['pending'], 0, ',', '.') }}</p>
                    <div class="ab-kpi-foot"><span>Em andamento agora</span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card ab-md-6 ab-xl-3">
        <div class="card-body">
            <div class="ab-kpi">
                <span class="ab-kpi-icon tone-success" aria-hidden="true"><i class="ti ti-circle-check"></i></span>
                <div class="ab-kpi-body">
                    <p class="ab-kpi-label">Concluídos no mês</p>
                    <p class="ab-kpi-value">{{ number_format((int) $kpi['completedMonth'], 0, ',', '.') }}</p>
                    <div class="ab-kpi-foot">
                        @if ($kpi['completedDelta'] === null)
                            <span>Sem base no mês anterior</span>
                        @else
                            <span class="ab-delta {{ $kpi['completedDelta'] > 0 ? 'up' : ($kpi['completedDelta'] < 0 ? 'down' : 'flat') }}">
                                <i class="ti ti-{{ $kpi['completedDelta'] > 0 ? 'arrow-up-right' : ($kpi['completedDelta'] < 0 ? 'arrow-down-right' : 'minus') }}" aria-hidden="true"></i>{{ $kpi['completedDelta'] > 0 ? '+' : ($kpi['completedDelta'] < 0 ? '−' : '') }}{{ str_replace('.', ',', (string) abs((float) $kpi['completedDelta'])) }}%
                            </span>
                            <span>vs. mês anterior</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card ab-md-6 ab-xl-3">
        <div class="card-body">
            <div class="ab-kpi">
                <span class="ab-kpi-icon tone-danger" aria-hidden="true"><i class="ti ti-circle-x"></i></span>
                <div class="ab-kpi-body">
                    <p class="ab-kpi-label">Recusados / expirados</p>
                    <p class="ab-kpi-value">{{ number_format((int) $kpi['failed'], 0, ',', '.') }}</p>
                    <div class="ab-kpi-foot"><span>Inclui cancelados</span></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Uso do plano (só quando há limite mensal) --}}
@if ($tenant->monthly_envelope_limit !== null)
    @foreach ([(int) $tenant->monthly_envelope_limit > 0 ? (int) min(100, round($usedThisMonth / $tenant->monthly_envelope_limit * 100)) : 100] as $planPct)
        <div class="card">
            <div class="card-body">
                <div class="ab-plan">
                    <div class="ab-plan-text">
                        <p>
                            <strong>Uso do plano{{ $tenant->plan ? ' · '.$tenant->plan : '' }}</strong>
                            <span class="ab-muted">{{ number_format((int) $usedThisMonth, 0, ',', '.') }} de {{ number_format((int) $tenant->monthly_envelope_limit, 0, ',', '.') }} envios no mês ({{ $planPct }}%)</span>
                        </p>
                        <div class="ab-progress{{ $planPct >= 90 ? ' is-danger' : '' }}" role="progressbar" aria-label="Uso do plano no mês" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $planPct }}">
                            <span style="width: {{ $planPct }}%"></span>
                        </div>
                    </div>
                    <a class="ab-link" href="{{ route('plan-usage.index') }}">Ver plano e uso</a>
                </div>
            </div>
        </div>
    @endforeach
@endif

{{-- Gráficos --}}
<div class="ab-grid">
    <div class="card ab-lg-8">
        <div class="card-header ab-card-head">
            <h5>Documentos por mês</h5>
            <div class="ab-legend" aria-hidden="true">
                <span><i class="ab-dot" style="background: var(--primary-500)"></i>Criados</span>
                <span><i class="ab-dot" style="background: #16a34a"></i>Concluídos</span>
            </div>
        </div>
        <div class="card-body">
            @if (count($labels) > 0 && array_sum($created) + array_sum($completed) > 0)
                <div id="ab-chart-months" class="ab-chart" role="img" aria-label="Gráfico de documentos criados e concluídos nos últimos 6 meses"></div>
            @else
                <div class="ab-empty">
                    <i class="ti ti-chart-bar" aria-hidden="true"></i>
                    <p>Ainda não há documentos nos últimos 6 meses.</p>
                    <a class="ab-link" href="{{ route('envelopes.create') }}">Criar o primeiro documento</a>
                </div>
            @endif
        </div>
    </div>

    <div class="card ab-lg-4">
        <div class="card-header ab-card-head"><h5>Por status</h5></div>
        <div class="card-body">
            @if ($statusChart->isEmpty())
                <div class="ab-empty">
                    <i class="ti ti-chart-donut" aria-hidden="true"></i>
                    <p>Ainda não há documentos.</p>
                </div>
            @else
                <div id="ab-chart-status" class="ab-chart-sm" role="img" aria-label="Gráfico de documentos por status"></div>
                <ul class="ab-status-list">
                    @foreach ($statusChart as $s)
                        <li>
                            <span><i class="ab-dot" data-ab-status-dot="{{ $loop->index }}"></i>{{ $s['label'] }}</span>
                            <strong>{{ number_format((int) $s['value'], 0, ',', '.') }}</strong>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

{{-- Listas --}}
<div class="ab-grid">
    <div class="card ab-lg-5">
        <div class="card-header ab-card-head"><h5>Aguardando assinatura</h5></div>
        <div class="card-body">
            @if ($pending->isEmpty())
                <div class="ab-empty">
                    <i class="ti ti-inbox" aria-hidden="true"></i>
                    <p>Nenhum documento aguardando assinatura.</p>
                </div>
            @else
                <ul class="ab-list">
                    @foreach ($pending as $e)
                        @foreach ([$e->signers_count ? (int) round($e->signed_count / $e->signers_count * 100) : 0] as $pct)
                            <li>
                                <div class="ab-list-row">
                                    <a href="{{ route('envelopes.show', $e) }}" title="{{ $e->title }}">{{ $e->title }}</a>
                                    <small>{{ (int) $e->signed_count }}/{{ (int) $e->signers_count }} assinaram</small>
                                </div>
                                <div class="ab-progress is-thin" role="progressbar" aria-label="Assinaturas de {{ $e->title }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}">
                                    <span style="width: {{ $pct }}%"></span>
                                </div>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="card ab-lg-7">
        <div class="card-header ab-card-head">
            <h5>Últimos documentos</h5>
            <a class="ab-link" href="{{ route('envelopes.index') }}">Ver todos</a>
        </div>
        @if ($recent->isEmpty())
            <div class="card-body">
                <div class="ab-empty">
                    <i class="ti ti-file-text" aria-hidden="true"></i>
                    <p>Nenhum documento ainda.</p>
                    <a class="ab-link" href="{{ route('envelopes.create') }}">Criar o primeiro documento</a>
                </div>
            </div>
        @else
            <div class="ab-table-wrap">
                <table class="ab-table">
                    <thead>
                        <tr><th scope="col">Título</th><th scope="col">Status</th><th scope="col">Criado em</th><th scope="col">Signatários</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $e)
                            <tr>
                                <td class="ab-title-cell"><a href="{{ route('envelopes.show', $e) }}" title="{{ $e->title }}">{{ $e->title }}</a></td>
                                <td><span class="ab-badge ab-badge--{{ $e->status->value }}">{{ __('status.'.$e->status->value) }}</span></td>
                                <td class="ab-nowrap">{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</td>
                                <td class="ab-nowrap">{{ (int) $e->signers_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    if (typeof window.ApexCharts !== 'function') { return; }

    var labels = @json($labels);
    var created = @json($created);
    var completed = @json($completed);
    var statusData = @json($statusChart->values());
    var statusPalette = ['#2563eb', '#0ea5e9', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#64748b', '#ea580c'];

    function primary() {
        return window.AbleTheme ? window.AbleTheme.color('--primary-500', '#2563eb') : '#2563eb';
    }
    function dark() {
        return window.AbleTheme ? window.AbleTheme.isDark() : false;
    }
    function base() {
        return {
            fontFamily: 'Inter var, ui-sans-serif, system-ui, sans-serif',
            foreColor: dark() ? '#bfbfbf' : '#5b6b79',
            toolbar: { show: false },
            background: 'transparent',
            animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches }
        };
    }
    function grid() {
        return { borderColor: dark() ? '#303f50' : '#e7eaee', strokeDashArray: 4 };
    }
    var number = function (v) { return Math.round(v).toLocaleString('pt-BR'); };

    var charts = [];

    var monthsEl = document.getElementById('ab-chart-months');
    if (monthsEl) {
        var months = new ApexCharts(monthsEl, {
            chart: Object.assign(base(), { type: 'bar', height: 300 }),
            theme: { mode: dark() ? 'dark' : 'light' },
            series: [{ name: 'Criados', data: created }, { name: 'Concluídos', data: completed }],
            colors: [primary(), '#16a34a'],
            plotOptions: { bar: { columnWidth: '55%', borderRadius: 4 } },
            dataLabels: { enabled: false },
            legend: { show: false },
            stroke: { show: true, width: 2, colors: ['transparent'] },
            grid: grid(),
            xaxis: { categories: labels, axisBorder: { show: false }, axisTicks: { show: false } },
            yaxis: { min: 0, forceNiceScale: true, labels: { formatter: number } },
            tooltip: { theme: dark() ? 'dark' : 'light', y: { formatter: number } }
        });
        months.render();
        charts.push(function () {
            months.updateOptions({ chart: base(), theme: { mode: dark() ? 'dark' : 'light' }, colors: [primary(), '#16a34a'], grid: grid(), tooltip: { theme: dark() ? 'dark' : 'light' } });
        });
    }

    var statusEl = document.getElementById('ab-chart-status');
    if (statusEl && statusData.length) {
        document.querySelectorAll('[data-ab-status-dot]').forEach(function (dot) {
            dot.style.background = statusPalette[Number(dot.getAttribute('data-ab-status-dot')) % statusPalette.length];
        });
        var total = statusData.reduce(function (sum, s) { return sum + Number(s.value); }, 0);
        var status = new ApexCharts(statusEl, {
            chart: Object.assign(base(), { type: 'donut', height: 240 }),
            theme: { mode: dark() ? 'dark' : 'light' },
            series: statusData.map(function (s) { return Number(s.value); }),
            labels: statusData.map(function (s) { return s.label; }),
            colors: statusPalette.slice(0, Math.max(1, statusData.length)),
            legend: { show: false },
            dataLabels: { enabled: false },
            stroke: { width: 2, colors: [dark() ? '#1b232d' : '#ffffff'] },
            plotOptions: { pie: { donut: { size: '72%', labels: { show: true, value: { formatter: function (v) { return number(Number(v)); } }, total: { show: true, label: 'Total', formatter: function () { return number(total); } } } } } },
            tooltip: { theme: dark() ? 'dark' : 'light', y: { formatter: number } }
        });
        status.render();
        charts.push(function () {
            status.updateOptions({ chart: base(), theme: { mode: dark() ? 'dark' : 'light' }, stroke: { colors: [dark() ? '#1b232d' : '#ffffff'] }, tooltip: { theme: dark() ? 'dark' : 'light' } });
        });
    }

    window.addEventListener('able:theme', function () {
        charts.forEach(function (refresh) { refresh(); });
    });
})();
</script>
@endpush
