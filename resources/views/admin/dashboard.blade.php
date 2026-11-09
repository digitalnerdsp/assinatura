@extends('layouts.app')
@section('title', 'Visão geral')
@section('section', 'Plataforma')
@section('content')
@php($mb = fn ($b) => $b >= 1073741824 ? round($b / 1073741824, 2).' GB' : round($b / 1048576, 1).' MB')
<div class="row row-cols-1 row-cols-md-2 row-cols-xl-4">
    @include('partials.kpi', ['label' => 'Clientes', 'value' => $tenants, 'icon' => 'bxs-buildings', 'tone' => 'info', 'grad' => 'blues', 'hint' => $tenantsNew30.' novos em 30 dias · '.$tenantsSuspended.' suspensos'])
    @include('partials.kpi', ['label' => 'Usuários', 'value' => $users, 'icon' => 'bxs-group', 'tone' => 'warning', 'grad' => 'orange', 'hint' => 'em todas as empresas'])
    @include('partials.kpi', ['label' => 'Envelopes no mês', 'value' => $envelopesMonth, 'icon' => 'bxs-file', 'tone' => 'success', 'grad' => 'ohhappiness', 'delta' => $envelopesDelta, 'hint' => $completedMonth.' concluídos · '.$envelopes.' no total'])
    @include('partials.kpi', ['label' => 'Armazenamento', 'value' => $mb($storageBytes), 'icon' => 'bxs-data', 'tone' => 'danger', 'grad' => 'burning', 'hint' => 'documentos enviados'])
</div>

<div class="row">
    <div class="col-12 col-lg-8 d-flex">
        <div class="card radius-10 w-100">
            <div class="card-header"><h6 class="mb-0">Envelopes e novos clientes por mês</h6></div>
            <div class="card-body">
                <div class="d-flex align-items-center ms-auto font-13 gap-2 mb-3">
                    <span class="border px-1 rounded"><i class="bx bxs-circle me-1" style="color:#14abef"></i>Envelopes criados</span>
                    <span class="border px-1 rounded"><i class="bx bxs-circle me-1" style="color:#42e695"></i>Concluídos</span>
                    <span class="border px-1 rounded"><i class="bx bxs-circle me-1" style="color:#ffc107"></i>Novos clientes</span>
                </div>
                <div class="chart-box"><canvas id="chartMonths"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4 d-flex">
        <div class="card radius-10 w-100">
            <div class="card-header"><h6 class="mb-0">Envelopes por status</h6></div>
            <div class="card-body">
                @if ($statusChart->isEmpty())<p class="text-secondary mb-0">Nenhum envelope ainda.</p>@else<div class="chart-box sm"><canvas id="chartStatus"></canvas></div>@endif
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
            <div class="card-header"><h6 class="mb-0">Clientes mais ativos no mês</h6></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Empresa</th><th class="text-end">Envelopes</th></tr></thead>
                    <tbody>
                    @forelse ($topTenants as $t)
                        <tr><td><a href="{{ route('admin.tenants.show', $t->id) }}">{{ $t->name }}</a></td><td class="text-end">{{ $t->total }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="text-secondary p-4">Sem atividade neste mês.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6 d-flex">
        <div class="card radius-10 w-100">
            <div class="card-header d-flex align-items-center"><h6 class="mb-0">Clientes recentes</h6><a class="ms-auto small" href="{{ route('admin.tenants.index') }}">ver todos</a></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Empresa</th><th>Plano</th><th>Desde</th></tr></thead>
                    <tbody>
                    @foreach ($recentTenants as $t)
                        <tr>
                            <td><a href="{{ route('admin.tenants.show', $t) }}">{{ $t->name }}</a></td>
                            <td><span class="badge bg-secondary">{{ $t->plan }}</span></td>
                            <td>{{ $t->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</td>
                        </tr>
                    @endforeach
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
                { label: 'Envelopes criados', data: @json($created), backgroundColor: grad(m, '#6078ea', '#17c5ea'), borderRadius: 20, borderWidth: 0 },
                { label: 'Concluídos', data: @json($completed), backgroundColor: grad(m, '#42e695', '#3bb2b8'), borderRadius: 20, borderWidth: 0 },
                { label: 'Novos clientes', data: @json($newTenants), type: 'line', borderColor: '#ffc107', backgroundColor: '#ffc107', tension: .35, pointRadius: 4 }
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
