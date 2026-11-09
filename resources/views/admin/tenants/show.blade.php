@extends('layouts.app')
@section('title', $tenant->name)
@section('content')
<p><a href="{{ route('admin.tenants.index') }}">← Clientes</a></p>
<h1>{{ $tenant->name }}
    @if ($tenant->isSuspended())<span class="badge refused">suspensa</span>@else<span class="badge completed">ativa</span>@endif</h1>

<div class="card">
    <div class="row">
        @if ($tenant->isSuspended())
            <form method="post" action="{{ route('admin.tenants.reactivate', $tenant) }}">@csrf<button class="btn">Reativar conta</button></form>
        @else
            <form method="post" action="{{ route('admin.tenants.suspend', $tenant) }}" onsubmit="return confirm('Suspender esta conta? Os usuários serão desconectados e a API deixa de responder.')">@csrf<button class="btn danger">Suspender conta</button></form>
        @endif
    </div>
    <p class="muted">Criada em {{ $tenant->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
        · Selagem: {{ $tenant->signingSetting?->provider ?? 'certificado da plataforma' }}
        · Envios neste mês: <strong>{{ $usedThisMonth }}</strong>@if ($tenant->monthly_envelope_limit !== null) de {{ $tenant->monthly_envelope_limit }}@endif</p>
</div>

<div class="card">
    <h2>Plano e limites</h2>
    <form method="post" action="{{ route('admin.tenants.update', $tenant) }}">
        @csrf @method('PUT')
        <div class="row">
            <div><label for="plan">Plano</label>
                <select id="plan" name="plan">
                    @foreach (['free' => 'Free', 'pro' => 'Pro', 'enterprise' => 'Enterprise'] as $k => $label)
                        <option value="{{ $k }}" @selected($tenant->plan === $k)>{{ $label }}</option>
                    @endforeach
                </select></div>
            <div><label for="limit">Limite mensal de envelopes</label>
                <input id="limit" type="number" min="0" name="monthly_envelope_limit" value="{{ $tenant->monthly_envelope_limit }}" placeholder="vazio = ilimitado"></div>
        </div>
        <label for="notes">Anotações internas</label>
        <textarea id="notes" name="admin_notes" rows="3">{{ $tenant->admin_notes }}</textarea>
        <p><button class="btn">Salvar</button></p>
    </form>
</div>

<div class="card">
    <h2>Usuários</h2>
    <table>
        <thead><tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Desde</th></tr></thead>
        <tbody>
        @foreach ($users as $u)
            <tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $u->role }}</td><td>{{ $u->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Últimos envelopes</h2>
    <p class="muted">Somente metadados; o conteúdo dos documentos não é exibido aqui.</p>
    <table>
        <thead><tr><th>Título</th><th>Status</th><th>Criado em</th></tr></thead>
        <tbody>
        @forelse ($recent as $e)
            <tr><td>{{ $e->title }}</td><td><span class="badge {{ $e->status->value }}">{{ __('status.'.$e->status->value) }}</span></td><td>{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">Nenhum envelope.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
