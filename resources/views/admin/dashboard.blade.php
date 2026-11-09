@extends('layouts.app')
@section('title', 'Administração')
@section('content')
@php($mb = fn ($b) => $b >= 1073741824 ? round($b / 1073741824, 2).' GB' : round($b / 1048576, 1).' MB')
<h1>Administração da plataforma</h1>
<p><a href="{{ route('admin.tenants.index') }}">Clientes</a> · <a href="{{ route('admin.settings') }}">Configurações</a></p>

<div class="row">
    <div class="card"><div class="muted">Clientes</div><h1 style="margin:0">{{ $tenants }}</h1><div class="muted">{{ $tenantsNew30 }} novos em 30 dias · {{ $tenantsSuspended }} suspensos</div></div>
    <div class="card"><div class="muted">Usuários</div><h1 style="margin:0">{{ $users }}</h1></div>
    <div class="card"><div class="muted">Envelopes no mês</div><h1 style="margin:0">{{ $envelopesMonth }}</h1><div class="muted">{{ $completedMonth }} concluídos · {{ $envelopes }} no total</div></div>
    <div class="card"><div class="muted">Armazenamento (documentos)</div><h1 style="margin:0">{{ $mb($storageBytes) }}</h1></div>
</div>

<div class="row">
    <div class="card">
        <h2>Envelopes por status</h2>
        <table><tbody>
        @forelse ($byStatus as $status => $total)
            <tr><td><span class="badge {{ $status }}">{{ __('status.'.$status) }}</span></td><td>{{ $total }}</td></tr>
        @empty
            <tr><td class="muted">Nenhum envelope ainda.</td></tr>
        @endforelse
        </tbody></table>
    </div>
    <div class="card">
        <h2>Clientes mais ativos no mês</h2>
        <table><tbody>
        @forelse ($topTenants as $t)
            <tr><td><a href="{{ route('admin.tenants.show', $t->id) }}">{{ $t->name }}</a></td><td>{{ $t->total }}</td></tr>
        @empty
            <tr><td class="muted">Sem atividade neste mês.</td></tr>
        @endforelse
        </tbody></table>
    </div>
</div>
@endsection
