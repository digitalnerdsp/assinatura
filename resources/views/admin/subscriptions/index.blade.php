@extends('layouts.app')
@section('title', 'Assinaturas')
@section('section', 'Plataforma')
@section('content')
@php
    $labels = ['trialing' => ['Em teste', 'bg-info'], 'active' => ['Ativa', 'bg-success'], 'blocked' => ['Bloqueada', 'bg-danger'], 'canceled' => ['Cancelada', 'bg-secondary']];
@endphp
<div class="card radius-10">
    <div class="card-body">
        <form method="get" class="row g-2 mb-3">
            <div class="col-12 col-md-4"><input type="search" class="form-control" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Buscar por nome da empresa" aria-label="Buscar empresa"></div>
            <div class="col-6 col-md-3"><select class="form-select" name="plan" aria-label="Plano"><option value="">Todos os planos</option>@foreach ($plans as $p)<option value="{{ $p->id }}" @selected(($filters['plan'] ?? '') === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
            <div class="col-6 col-md-3"><select class="form-select" name="status" aria-label="Situação"><option value="">Todas as situações</option>@foreach ($labels as $k => $l)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $l[0] }}</option>@endforeach</select></div>
            <div class="col-12 col-md-auto d-flex gap-2"><button class="btn btn-outline-primary drive-tap"><i class="bx bx-search"></i> Filtrar</button>@if (array_filter($filters))<a class="btn btn-outline-secondary drive-tap" href="{{ route('admin.subscriptions.index') }}">Limpar</a>@endif</div>
        </form>
        <div class="table-responsive">
            <table class="table align-middle mb-0 drive-docs drive-docs-md">
                <thead class="table-light"><tr><th>Empresa</th><th>Plano</th><th>Situação</th><th>Documentos no período</th><th>Créditos</th><th>Cobrança</th></tr></thead>
                <tbody>
                @forelse ($subs as $s)
                    <tr>
                        <td data-label="Empresa"><a class="fw-semibold" href="{{ route('admin.subscriptions.show', $s->tenant_id) }}">{{ $s->tenant->name }}</a></td>
                        <td data-label="Plano">{{ $s->plan->name }}</td>
                        <td data-label="Situação"><span class="badge {{ $labels[$s->status][1] }}">{{ $labels[$s->status][0] }}</span>@if ($s->status === 'trialing' && $s->trial_ends_at) <span class="small text-secondary">até {{ $s->trial_ends_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</span>@endif</td>
                        <td data-label="Documentos no período">{{ $s->quota_used }}</td>
                        <td data-label="Créditos">{{ $s->credits_balance }}</td>
                        <td data-label="Cobrança">{{ $s->billing_exempt ? 'Isenta' : 'Normal' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-secondary p-4">Nenhuma assinatura encontrada.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $subs->links('pagination::bootstrap-5') }}</div>
    </div>
</div>
@endsection
