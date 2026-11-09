@extends('layouts.app')
@section('title', $tenant->name)
@section('section', 'Clientes')
@section('content')
<div class="card radius-10">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <h5 class="mb-0">{{ $tenant->name }}</h5>
            @if ($tenant->isSuspended())<span class="badge bg-danger">suspensa</span>@else<span class="badge bg-success">ativa</span>@endif
            <div class="ms-auto">
                @if ($tenant->isSuspended())
                    <form method="post" action="{{ route('admin.tenants.reactivate', $tenant) }}">@csrf<button class="btn btn-success"><i class="bx bx-play-circle"></i> Reativar conta</button></form>
                @else
                    <form method="post" action="{{ route('admin.tenants.suspend', $tenant) }}" onsubmit="return confirm('Suspender esta conta? Os usuários serão desconectados e a API deixa de responder.')">@csrf<button class="btn btn-danger"><i class="bx bx-pause-circle"></i> Suspender conta</button></form>
                @endif
            </div>
        </div>
        <p class="text-secondary mt-2 mb-0">Criada em {{ $tenant->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
            · Selagem: {{ $tenant->signingSetting?->provider ?? 'certificado da plataforma' }}
            · Envios neste mês: <strong>{{ $usedThisMonth }}</strong>@if ($tenant->monthly_envelope_limit !== null) de {{ $tenant->monthly_envelope_limit }}@endif</p>
    </div>
</div>

<div class="row">
    <div class="col-12 col-xl-5 d-flex">
        <div class="card radius-10 w-100">
            <div class="card-header"><h6 class="mb-0">Plano e limites</h6></div>
            <div class="card-body">
                <form method="post" action="{{ route('admin.tenants.update', $tenant) }}">
                    @csrf @method('PUT')
                    <div class="mb-3"><label for="plan" class="form-label">Plano</label>
                        <select id="plan" class="form-select" name="plan">
                            @foreach (['free' => 'Free', 'pro' => 'Pro', 'enterprise' => 'Enterprise'] as $k => $label)
                                <option value="{{ $k }}" @selected($tenant->plan === $k)>{{ $label }}</option>
                            @endforeach
                        </select></div>
                    <div class="mb-3"><label for="limit" class="form-label">Limite mensal de envelopes</label>
                        <input id="limit" type="number" min="0" class="form-control" name="monthly_envelope_limit" value="{{ $tenant->monthly_envelope_limit }}" placeholder="vazio = ilimitado"></div>
                    <div class="mb-3"><label for="notes" class="form-label">Anotações internas</label>
                        <textarea id="notes" class="form-control" name="admin_notes" rows="3">{{ $tenant->admin_notes }}</textarea></div>
                    <button class="btn btn-primary">Salvar</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-7 d-flex">
        <div class="card radius-10 w-100">
            <div class="card-header"><h6 class="mb-0">Usuários</h6></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Desde</th></tr></thead>
                    <tbody>
                    @foreach ($users as $u)
                        <tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td><span class="badge bg-secondary">{{ $u->role }}</span></td><td>{{ $u->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0">Últimos envelopes</h6><small class="text-secondary">Somente metadados; o conteúdo dos documentos não é exibido aqui.</small></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Título</th><th>Status</th><th>Criado em</th></tr></thead>
            <tbody>
            @forelse ($recent as $e)
                <tr><td>{{ $e->title }}</td><td>@include('partials.status-badge', ['status' => $e->status->value])</td><td>{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td></tr>
            @empty
                <tr><td colspan="3" class="text-secondary p-4">Nenhum envelope.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
