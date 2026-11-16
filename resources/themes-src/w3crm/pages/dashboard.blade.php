@extends('theme::layouts.app')
@section('title', 'Painel')
@section('section', 'Assinaturas')
@section('native', '1')

@section('content')
{{-- Indicadores do mês --}}
<div class="row">
    @foreach ([
        ['label' => 'Enviados no mês', 'value' => $kpi['month'], 'icon' => 'la-paper-plane', 'tone' => 'primary', 'delta' => $kpi['monthDelta'], 'hint' => $kpi['total'].' no total'],
        ['label' => 'Aguardando assinatura', 'value' => $kpi['pending'], 'icon' => 'la-hourglass-half', 'tone' => 'warning', 'delta' => null, 'hint' => 'em andamento agora'],
        ['label' => 'Concluídos no mês', 'value' => $kpi['completedMonth'], 'icon' => 'la-check-circle', 'tone' => 'success', 'delta' => $kpi['completedDelta'], 'hint' => 'documentos selados'],
        ['label' => 'Recusados ou expirados', 'value' => $kpi['failed'], 'icon' => 'la-times-circle', 'tone' => 'danger', 'delta' => null, 'hint' => 'inclui cancelados'],
    ] as $card)
        <div class="xl:w-1/4 sm:w-1/2 w-full">
            <div class="card w3-card w3-kpi w3-kpi--{{ $card['tone'] }}">
                <div class="w3-kpi-top">
                    <div>
                        <h2 class="w3-kpi-label">{{ $card['label'] }}</h2>
                        <p class="w3-kpi-value">{{ number_format((int) $card['value'], 0, ',', '.') }}</p>
                    </div>
                    <span class="w3-kpi-icon" aria-hidden="true"><i class="las {{ $card['icon'] }}"></i></span>
                </div>
                <p class="w3-kpi-foot">
                    @if ($card['delta'] !== null)
                        <span class="w3-delta {{ $card['delta'] >= 0 ? 'w3-delta--up' : 'w3-delta--down' }}">
                            <i class="las {{ $card['delta'] >= 0 ? 'la-arrow-up' : 'la-arrow-down' }}" aria-hidden="true"></i>{{ $card['delta'] >= 0 ? '+' : '−' }}{{ number_format(abs((float) $card['delta']), 1, ',', '.') }}%
                        </span>
                        <span class="w3-muted">em relação ao mês anterior</span>
                    @else
                        <span class="w3-muted">{{ $card['hint'] }}</span>
                    @endif
                </p>
            </div>
        </div>
    @endforeach
</div>

{{-- Gráficos --}}
<div class="row">
    <div class="xl:w-2/3 w-full">
        <section class="card w3-card" aria-labelledby="w3-months-title">
            <div class="w3-card-head">
                <h2 class="w3-card-title" id="w3-months-title">Documentos por mês</h2>
                <div class="w3-legend" aria-hidden="true">
                    <span><i class="w3-dot w3-dot--primary"></i>Criados</span>
                    <span><i class="w3-dot w3-dot--success"></i>Concluídos</span>
                </div>
            </div>
            <div class="w3-card-body">
                @if (count($labels) === 0 || (array_sum($created) + array_sum($completed)) === 0)
                    <div class="w3-empty">
                        <i class="las la-chart-bar" aria-hidden="true"></i>
                        <p>Ainda não há documentos nos últimos 6 meses.</p>
                        <a class="w3-btn w3-btn--primary" href="{{ route('envelopes.create') }}"><i class="las la-plus" aria-hidden="true"></i>Criar documento</a>
                    </div>
                @else
                    <div id="w3-chart-months" class="w3-chart" role="img" aria-label="Gráfico de barras: documentos criados e concluídos nos últimos 6 meses"></div>
                    <table class="w3-sr">
                        <caption>Documentos por mês</caption>
                        <thead><tr><th scope="col">Mês</th><th scope="col">Criados</th><th scope="col">Concluídos</th></tr></thead>
                        <tbody>
                            @foreach ($labels as $i => $label)
                                <tr><th scope="row">{{ $label }}</th><td>{{ $created[$i] ?? 0 }}</td><td>{{ $completed[$i] ?? 0 }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
            @if ($tenant->monthly_envelope_limit !== null)
                @foreach ([$tenant->monthly_envelope_limit > 0 ? min(100, (int) round($usedThisMonth / $tenant->monthly_envelope_limit * 100)) : 100] as $pct)
                    <div class="w3-card-foot">
                        <div class="w3-row-between">
                            <span>Uso do plano{{ $tenant->plan ? ' ('.$tenant->plan.')' : '' }}</span>
                            <span><strong>{{ $usedThisMonth }}</strong> de {{ $tenant->monthly_envelope_limit }} envios no mês</span>
                        </div>
                        <div class="w3-progress w3-progress--lg" role="progressbar" aria-label="Uso do plano no mês" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}">
                            <span class="w3-progress-bar {{ $pct >= 90 ? 'w3-progress-bar--danger' : '' }}" style="width: {{ $pct }}%"></span>
                        </div>
                        <a class="w3-link w3-small" href="{{ route('plan-usage.index') }}">Ver plano e uso</a>
                    </div>
                @endforeach
            @endif
        </section>
    </div>
    <div class="xl:w-1/3 w-full">
        <section class="card w3-card" aria-labelledby="w3-status-title">
            <div class="w3-card-head">
                <h2 class="w3-card-title" id="w3-status-title">Por status</h2>
            </div>
            <div class="w3-card-body">
                @if ($statusChart->isEmpty())
                    <div class="w3-empty">
                        <i class="las la-chart-pie" aria-hidden="true"></i>
                        <p>Ainda não há documentos.</p>
                    </div>
                @else
                    <div id="w3-chart-status" class="w3-chart w3-chart--sm" role="img" aria-label="Gráfico de rosca: documentos por status"></div>
                    <ul class="w3-status-list">
                        @foreach ($statusChart as $i => $s)
                            <li>
                                <span><i class="w3-dot w3-dot--c{{ $i % 8 }}" aria-hidden="true"></i>{{ $s['label'] }}</span>
                                <strong>{{ $s['value'] }}</strong>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    </div>
</div>

{{-- Listas --}}
<div class="row">
    <div class="xl:w-1/2 w-full">
        <section class="card w3-card" aria-labelledby="w3-pending-title">
            <div class="w3-card-head">
                <h2 class="w3-card-title" id="w3-pending-title">Aguardando assinatura</h2>
                <a class="w3-link w3-small" href="{{ route('envelopes.index') }}">Ver todos</a>
            </div>
            <div class="w3-card-body">
                @forelse ($pending as $e)
                    @foreach ([$e->signers_count ? min(100, (int) round($e->signed_count / $e->signers_count * 100)) : 0] as $pct)
                        <div class="w3-pending">
                            <div class="w3-row-between">
                                <a class="w3-link w3-pending-title" href="{{ route('envelopes.show', $e) }}">{{ $e->title }}</a>
                                <span class="w3-muted w3-small w3-nowrap">{{ $e->signed_count }}/{{ $e->signers_count }} assinaram</span>
                            </div>
                            <div class="w3-progress" role="progressbar" aria-label="Assinaturas de {{ $e->title }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}">
                                <span class="w3-progress-bar" style="width: {{ $pct }}%"></span>
                            </div>
                        </div>
                    @endforeach
                @empty
                    <div class="w3-empty">
                        <i class="las la-inbox" aria-hidden="true"></i>
                        <p>Nenhum documento aguardando assinatura.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
    <div class="xl:w-1/2 w-full">
        <section class="card w3-card" aria-labelledby="w3-recent-title">
            <div class="w3-card-head">
                <h2 class="w3-card-title" id="w3-recent-title">Últimos documentos</h2>
                <a class="w3-link w3-small" href="{{ route('envelopes.index') }}">Ver todos</a>
            </div>
            @if ($recent->isEmpty())
                <div class="w3-card-body">
                    <div class="w3-empty">
                        <i class="las la-file-signature" aria-hidden="true"></i>
                        <p>Nenhum documento ainda.</p>
                        <a class="w3-btn w3-btn--primary" href="{{ route('envelopes.create') }}"><i class="las la-plus" aria-hidden="true"></i>Criar o primeiro</a>
                    </div>
                </div>
            @else
                <div class="w3-table-wrap">
                    <table class="w3-table">
                        <thead>
                            <tr><th scope="col">Título</th><th scope="col">Status</th><th scope="col">Criado em</th><th scope="col" class="w3-num">Signatários</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($recent as $e)
                                <tr>
                                    <td class="w3-cell-title"><a class="w3-link" href="{{ route('envelopes.show', $e) }}">{{ $e->title }}</a></td>
                                    <td><span class="w3-badge w3-badge--{{ [
                                        'draft' => 'neutral', 'sent' => 'info', 'in_progress' => 'primary', 'sealing' => 'warning',
                                        'completed' => 'success', 'refused' => 'danger', 'expired' => 'dark', 'cancelled' => 'dark',
                                    ][$e->status->value] ?? 'neutral' }}">{{ __('status.'.$e->status->value) }}</span></td>
                                    <td class="w3-nowrap">{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</td>
                                    <td class="w3-num">{{ $e->signers_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    if (typeof window.ApexCharts === 'undefined') { return; }
    var data = {
        labels: @json($labels),
        created: @json($created),
        completed: @json($completed),
        statusLabels: @json($statusChart->pluck('label')->values()),
        statusValues: @json($statusChart->pluck('value')->values())
    };
    var css = getComputedStyle(document.body);
    var primary = (css.getPropertyValue('--primary') || '#0969c9').trim();
    var palette = [primary, '#0e7490', '#1f9d55', '#b7791f', '#c53030', '#6b46c1', '#4a5568', '#c05621'];
    function mode() { return document.body.getAttribute('data-theme-version') === 'dark' ? 'dark' : 'light'; }
    function fore() { return mode() === 'dark' ? '#c9c9c9' : '#4b5563'; }
    function grid() { return mode() === 'dark' ? '#363636' : '#eeeeee'; }
    var charts = [];

    var m = document.getElementById('w3-chart-months');
    if (m) {
        var bar = new ApexCharts(m, {
            chart: { type: 'bar', height: 300, toolbar: { show: false }, fontFamily: 'inherit', foreColor: fore(), background: 'transparent', animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches } },
            theme: { mode: mode() },
            series: [{ name: 'Criados', data: data.created }, { name: 'Concluídos', data: data.completed }],
            colors: [primary, '#1f9d55'],
            plotOptions: { bar: { columnWidth: '45%', borderRadius: 4 } },
            dataLabels: { enabled: false },
            legend: { show: false },
            stroke: { show: true, width: 3, colors: ['transparent'] },
            grid: { borderColor: grid(), strokeDashArray: 4 },
            xaxis: { categories: data.labels, axisBorder: { show: false }, axisTicks: { show: false } },
            yaxis: { min: 0, forceNiceScale: true, labels: { formatter: function (v) { return Math.round(v); } } },
            tooltip: { theme: mode(), y: { formatter: function (v) { return v + (v === 1 ? ' documento' : ' documentos'); } } }
        });
        bar.render();
        charts.push(bar);
    }

    var s = document.getElementById('w3-chart-status');
    if (s) {
        var donut = new ApexCharts(s, {
            chart: { type: 'donut', height: 240, fontFamily: 'inherit', foreColor: fore(), background: 'transparent' },
            theme: { mode: mode() },
            series: data.statusValues,
            labels: data.statusLabels,
            colors: palette,
            legend: { show: false },
            dataLabels: { enabled: false },
            stroke: { width: 2, colors: [mode() === 'dark' ? '#242424' : '#ffffff'] },
            plotOptions: { pie: { donut: { size: '70%', labels: { show: true, total: { show: true, label: 'Total', color: fore() } } } } },
            tooltip: { theme: mode() }
        });
        donut.render();
        charts.push(donut);
    }

    document.addEventListener('w3:theme', function () {
        charts.forEach(function (c) {
            c.updateOptions({ theme: { mode: mode() }, chart: { foreColor: fore() }, grid: { borderColor: grid() }, tooltip: { theme: mode() } });
        });
    });
})();
</script>
@endpush
