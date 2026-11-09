@extends('layouts.app')
@section('title', 'Clientes')
@section('content')
<p><a href="{{ route('admin.dashboard') }}">← Administração</a></p>
<h1>Clientes</h1>
<form method="get" class="row" style="align-items:end;margin-bottom:12px">
    <div><label for="q">Buscar por nome</label><input id="q" type="text" name="q" value="{{ $q }}"></div>
    <div style="flex:0 0 auto"><button class="btn">Buscar</button></div>
</form>
<div class="card">
    <table>
        <thead><tr><th>Empresa</th><th>Plano</th><th>Usuários</th><th>Envelopes</th><th>Status</th><th>Criada em</th></tr></thead>
        <tbody>
        @forelse ($tenants as $t)
            <tr>
                <td><a href="{{ route('admin.tenants.show', $t) }}">{{ $t->name }}</a></td>
                <td>{{ $t->plan }}</td><td>{{ $t->users_count }}</td><td>{{ $t->envelopes_count }}</td>
                <td>@if ($t->isSuspended())<span class="badge refused">suspensa</span>@else<span class="badge completed">ativa</span>@endif</td>
                <td>{{ $t->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Nenhum cliente encontrado.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $tenants->links() }}
</div>
@endsection
