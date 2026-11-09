@extends('layouts.app')
@section('title', 'Painel')
@section('section', 'Assinaturas')
@section('actions')<a href="{{ route('envelopes.create') }}" class="btn btn-primary"><i class="bx bx-plus"></i> Novo envelope</a>@endsection
@section('content')
<div class="row row-cols-1 row-cols-md-2 row-cols-xl-4">
    @include('partials.kpi', ['label' => 'Envelopes no mês', 'value' => $kpi['month'], 'icon' => 'bxs-file', 'tone' => 'info', 'grad' => 'blues', 'delta' => $kpi['monthDelta'], 'hint' => $kpi['total'].' no total'])
    @include('partials.kpi', ['label' => 'Aguardando assinatura', 'value' => $kpi['pending'], 'icon' => 'bxs-time-five', 'tone' => 'warning', 'grad' => 'orange', 'hint' => 'em andamento agora'])
    @include('partials.kpi', ['label' => 'Concluídos no mês', 'value' => $kpi['completedMonth'], 'icon' => 'bxs-check-circle', 'tone' => 'success', 'grad' => 'ohhappiness', 'delta' => $kpi['completedDelta'], 'hint' => 'documentos selados'])
    @include('partials.kpi', ['label' => 'Recusados / expirados', 'value' => $kpi['failed'], 'icon' => 'bxs-x-circle', 'tone' => 'danger', 'grad' => 'burning', 'hint' => 'inclui cancelados'])
</div>

<div class="row">
    <div class="col-12 col-lg-8 d-flex">
        <div class="card radius-10 w-100">
            <div class="card-header"><h6 class="mb-0">Envelopes por mês</h6></div>
            <div class="card-body">
                <div class="d-flex align-items-center ms-auto font-13 gap-2 mb-3">
                    <span class="border px-1 rounded"><i class="bx bxs-circle me-1" style="color:#14abef"></i>Criados</span>
                    <span class="border px-1 rounded"><i class="bx bxs-circle me-1" style="color:#42e695"></i>Concluídos</span>
                </div>
                <div class="chart-box"><canvas id="chartMonths"></canvas></div>
            </div>
            @if ($tenant->monthly_envelope_limit !== null)
                @php($pct = $tenant->monthly_envelope_limit > 0 ? min(100, round($usedThisMonth / $tenant->monthly_envelope_limit * 100)) : 100)
                <div class="card-footer border-top">
                    <div class="d-flex justify-content-between small mb-1"><span>Uso do plano ({{ $tenant->plan }})</span><span>{{ $usedThisMonth }} de {{ $tenant->monthly_envelope_limit }} envios no mês</span></div>
                    <div class="progress" style="height:8px"><div class="progress-bar {{ $pct >= 90 ? 'bg-danger' : 'bg-primary' }}" style="width:{{ $pct }}%"></div></div>
                </div>
            @endif
        </div>
    </div>
    <div class="col-12 col-lg-4 d-flex">
        <div class="card radius-10 w-100">
            <div class="card-header"><h6 class="mb-0">Por status</h6></div>
            <div class="card-body">
                @if ($statusChart->isEmpty())
                    <p class="text-secondary mb-0">Ainda não há envelopes.</p>
                @else
                    <div class="chart-box sm"><canvas id="chartStatus"></canvas></div>
                @endif
            </div>
            <ul class="list-group list-group-flush">
                @foreach ($statusChart as $s)
                    <li class="list-group-item d-flex bg-transparent justify-content-between align-items-center">{{ $s['label'] }}<span class="badge bg-primary rounded-pill">{{ $s['value'] }}</span></li>
                @endforeach
            </ul>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12 col-xl-6 d-flex">
        <div class="card radius-10 w-100">
            <div class="card-header"><h6 class="mb-0">Aguardando assinatura</h6></div>
            <div class="card-body">
                @forelse ($pending as $e)
                    @php($pct = $e->signers_count ? round($e->signed_count / $e->signers_count * 100) : 0)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <a href="{{ route('envelopes.show', $e) }}" class="fw-semibold">{{ $e->title }}</a>
                            <small class="text-secondary">{{ $e->signed_count }}/{{ $e->signers_count }} assinaram</small>
                        </div>
                        <div class="progress mt-1" style="height:6px"><div class="progress-bar bg-gradient-blues" style="width:{{ $pct }}%"></div></div>
                    </div>
                @empty
                    <p class="text-secondary mb-0">Nenhum envelope aguardando assinatura.</p>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6 d-flex">
        <div class="card radius-10 w-100">
            <div class="card-header d-flex align-items-center"><h6 class="mb-0">Últimos envelopes</h6><a class="ms-auto small" href="{{ route('envelopes.index') }}">ver todos</a></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Título</th><th>Status</th><th>Criado</th></tr></thead>
                    <tbody>
                    @forelse ($recent as $e)
                        <tr>
                            <td><a href="{{ route('envelopes.show', $e) }}">{{ $e->title }}</a><div class="small text-secondary">{{ $e->signers_count }} signatário(s)</div></td>
                            <td>@include('partials.status-badge', ['status' => $e->status->value])</td>
                            <td class="text-nowrap">{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-secondary p-4">Nenhum envelope ainda. <a href="{{ route('envelopes.create') }}">Crie o primeiro</a>.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/plugins/chartjs/js/chart.js') }}"></script>
<script>
(function () {
    const grad = (ctx, a, b) => { const g = ctx.createLinearGradient(0, 0, 0, 300); g.addColorStop(0, a); g.addColorStop(1, b); return g; };
    const m = document.getElementById('chartMonths').getContext('2d');
    new Chart(m, {
        type: 'bar',
        data: {
            labels: @json($labels),
            datasets: [
                { label: 'Criados', data: @json($created), backgroundColor: grad(m, '#6078ea', '#17c5ea'), borderRadius: 20, borderWidth: 0 },
                { label: 'Concluídos', data: @json($completed), backgroundColor: grad(m, '#42e695', '#3bb2b8'), borderRadius: 20, borderWidth: 0 }
            ]
        },
        options: { maintainAspectRatio: false, barPercentage: 0.5, categoryPercentage: 0.8, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
    const s = document.getElementById('chartStatus');
    if (s) {
        new Chart(s.getContext('2d'), {
            type: 'doughnut',
            data: { labels: @json($statusChart->pluck('label')), datasets: [{ data: @json($statusChart->pluck('value')), backgroundColor: ['#6078ea', '#17c5ea', '#42e695', '#ffc107', '#ee0979', '#8e54e9', '#6c757d', '#fd7e14'], borderWidth: 0 }] },
            options: { maintainAspectRatio: false, cutout: '70%', plugins: { legend: { display: false } } }
        });
    }
})();
</script>
@endpush
