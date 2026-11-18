@extends('layouts.app')
@section('title', 'Contratos')
@section('section', 'Contratos')
@section('actions')<a class="btn btn-outline-primary drive-tap" href="{{ route('contracts.export', $filters) }}"><i class="bx bx-download"></i> Exportar CSV</a>@endsection
@section('content')
@php
    $faixa = $filters['faixa'];
    $extra = collect($filters)->except('faixa')->all();
    $cards = [
        ['vencidos', 'Vencidos', $counts['overdue'], 'danger'],
        ['ate30', 'Até 30 dias', $counts['d30'], 'warning'],
        ['31a60', '31 a 60 dias', $counts['d60'], 'warning'],
        ['61a90', '61 a 90 dias', $counts['d90'], 'info'],
        ['vigentes', 'Vigentes (+90)', $counts['valid'], 'success'],
    ];
    $tones = ['overdue' => 'danger', 'd30' => 'warning', 'd60' => 'warning', 'd90' => 'info', 'valid' => 'success'];
    $plural = fn (int $n) => $n === 1 ? 'dia' : 'dias';
    $total = array_sum($chart['values']);
@endphp

<div class="row g-3 mb-3">
    @foreach ($cards as [$key, $label, $n, $tone])
        <div class="col-6 col-md-4 col-xl">
            <a class="text-decoration-none" href="{{ route('contracts.index', ['faixa' => $key] + $extra) }}" aria-label="{{ $label }}: {{ $n }}">
                <div class="card radius-10 h-100 mb-0 border-{{ $tone }} {{ $faixa === $key ? 'border-2' : '' }}"><div class="card-body">
                    <div class="fw-semibold text-body">{{ $label }}</div>
                    <div class="fs-3 fw-bold text-{{ $tone === 'warning' || $tone === 'info' ? 'body' : $tone }}">{{ number_format($n, 0, ',', '.') }}</div>
                </div></div>
            </a>
        </div>
    @endforeach
</div>

<div class="card radius-10">
    <div class="card-body">
        <form method="get" action="{{ route('contracts.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-3"><label class="form-label" for="c-faixa">Mostrar</label>
                <select class="form-select" id="c-faixa" name="faixa">
                    @foreach (['avencer' => 'A vencer ('.$counts['soon'].')', 'vencidos' => 'Vencidos', 'ate30' => 'Até 30 dias', '31a60' => '31 a 60 dias', '61a90' => '61 a 90 dias', 'vigentes' => 'Vigentes (+90)', 'todos' => 'Todos'] as $v => $l)<option value="{{ $v }}" @selected($faixa === $v)>{{ $l }}</option>@endforeach
                </select></div>
            <div class="col-12 col-md-3"><label class="form-label" for="c-folder">Pasta</label>
                <select class="form-select" id="c-folder" name="folder"><option value="">Todas</option>@foreach ($folderPaths as $id => $path)<option value="{{ $id }}" @selected(($filters['folder'] ?? '') === $id)>{{ $path }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label" for="c-tag">Etiqueta</label>
                <select class="form-select" id="c-tag" name="tag"><option value="">Todas</option>@foreach ($tags as $t)<option value="{{ $t->id }}" @selected(($filters['tag'] ?? '') === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
            <div class="col-6 col-md-4"><label class="form-label" for="c-q">Buscar</label><input type="search" class="form-control" id="c-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Título ou signatário" maxlength="100"></div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary drive-tap"><i class="bx bx-filter-alt"></i> Aplicar</button>
                @if ($extra || $faixa !== 'avencer')<a class="btn btn-outline-secondary drive-tap" href="{{ route('contracts.index') }}">Limpar</a>@endif
            </div>
        </form>
    </div>
</div>

<div class="card radius-10">
    <div class="card-body">
        <h5 class="mb-3">Vencimentos nos próximos 12 meses</h5>
        @if ($total === 0)
            <p class="text-secondary mb-0">Nenhum vencimento nos próximos 12 meses.</p>
        @else
            <div style="position:relative;height:220px"><canvas id="chartContracts" role="img" aria-label="Contratos que vencem em cada um dos próximos 12 meses"></canvas></div>
        @endif
    </div>
</div>

<div class="card radius-10">
    <div class="card-body">
        <h5 class="mb-3">Contratos</h5>
        @if ($rows->isEmpty())
            @if ($counts['overdue'] + $counts['d30'] + $counts['d60'] + $counts['d90'] + $counts['valid'] === 0 && ! $extra)
                <p class="mb-1 fw-semibold">Nenhum contrato com vencimento monitorado.</p>
                <p class="text-secondary mb-0">Ao criar um documento, no último passo antes de enviar, marque “Lembrar do vencimento deste contrato” e informe a data. Quando todos assinarem, ele aparece aqui.</p>
            @else
                <p class="text-secondary mb-0">Nenhum contrato nesta faixa ou com esses filtros.</p>
            @endif
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0 drive-docs drive-docs-md">
                    <thead class="table-light"><tr><th>Contrato</th><th>Vencimento</th><th>Prazo</th><th>Aviso</th><th>Pasta</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $e)
                        @php
                            $left = $watch->daysLeft($e, $today);
                            $tone = $tones[$watch->band($left)];
                            $label = $left < 0 ? 'Vencido há '.abs($left).' '.$plural(abs($left)) : ($left === 0 ? 'Vence hoje' : $left.' '.$plural($left));
                        @endphp
                        <tr>
                            <td data-label="Contrato">
                                <a class="fw-semibold" href="{{ route('envelopes.show', $e) }}#vigencia">{{ $e->title }}</a>
                                @foreach ($e->tags as $tg)<span class="badge bg-{{ $tg->color ?: 'secondary' }} ms-1">{{ $tg->name }}</span>@endforeach
                            </td>
                            <td data-label="Vencimento">{{ $e->contract_expires_on->format('d/m/Y') }}</td>
                            <td data-label="Prazo"><span class="badge bg-{{ $tone }} {{ in_array($tone, ['warning', 'info']) ? 'text-dark' : '' }}">{{ $label }}</span></td>
                            <td data-label="Aviso">{{ $e->contract_alert_days }} dias antes</td>
                            <td data-label="Pasta">{{ $folderPaths[$e->folder_id] ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $rows->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection

@if ($total > 0)
@push('scripts')
<script src="{{ asset('assets/plugins/chartjs/js/chart.js') }}"></script>
<script>
(function () {
    const el = document.getElementById('chartContracts');
    if (!el || typeof Chart === 'undefined') return;
    new Chart(el.getContext('2d'), {
        type: 'bar',
        data: { labels: @json($chart['labels']), datasets: [{ label: 'Contratos que vencem', data: @json($chart['values']), backgroundColor: '#6078ea', borderRadius: 4 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
})();
</script>
@endpush
@endif
