@extends('layouts.app')
@section('title', 'Pacotes de tokens')
@section('section', 'Plataforma')
@section('content')
@php
    $m = fn ($v, $d = 2) => number_format((float) $v, $d, ',', '.');
    $i = fn ($v) => number_format((int) $v, 0, ',', '.');
    $status = ['pending' => 'aguardando', 'approved' => 'liberado', 'rejected' => 'recusado', 'canceled' => 'cancelado'];
@endphp
@if (session('status'))<div class="alert alert-success border-0">{{ session('status') }}</div>@endif
@error('package')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-time-five me-1"></i> Pedidos aguardando liberação</h6></div>
    <div class="card-body">
        @if ($pending->isEmpty())
            <p class="text-secondary mb-0">Nenhum pedido aguardando.</p>
        @else
            <p class="text-secondary small">Confirme o pagamento fora do sistema (por enquanto não há gateway) e então libere: os tokens entram na carteira da empresa com a validade do pacote.</p>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>Empresa</th><th>Pacote</th><th class="text-end">Tokens</th><th class="text-end">Preço</th><th>Pedido em</th><th></th></tr></thead>
                <tbody>
                @foreach ($pending as $o)
                    <tr>
                        <td>{{ $o->tenant?->name }}</td><td>{{ $o->name }}</td><td class="text-end">{{ $i($o->tokens) }}</td><td class="text-end">R$ {{ $m($o->price_cents / 100) }}</td><td>{{ $o->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                        <td class="text-end text-nowrap">
                            <form class="d-inline" method="post" action="{{ route('admin.packages.approve', $o) }}" data-confirm="Liberar {{ $i($o->tokens) }} tokens para {{ $o->tenant?->name }}? Confirme que o pagamento de R$ {{ $m($o->price_cents / 100) }} foi recebido.">@csrf<button class="btn btn-success btn-sm">Liberar</button></form>
                            <form class="d-inline" method="post" action="{{ route('admin.packages.reject', $o) }}">@csrf<input type="hidden" name="note" value="Pedido recusado"><button class="btn btn-outline-danger btn-sm">Recusar</button></form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-package me-1"></i> Catálogo de pacotes</h6></div>
    <div class="card-body">
        @if ($perMillion === null)
            <div class="alert alert-warning border-0">Salve a tabela de preços em Economia dos tokens para ver o custo e a margem de cada pacote.</div>
        @endif
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Nome</th><th>Tokens</th><th>Preço (R$)</th><th>Validade (meses)</th><th>Ativo</th><th class="text-end">Custo</th><th class="text-end">Preço mínimo</th><th class="text-end">Margem</th><th></th></tr></thead>
                <tbody>
                @foreach ($packages as $p)
                    @php
                        $cost = $perMillion ? $p->tokens / 1_000_000 * $perMillion['cost_brl'] : null;
                        $floor = $perMillion ? $p->tokens / 1_000_000 * $perMillion['sale_brl'] : null;
                        $margin = $cost !== null && $cost > 0 ? ($p->price_cents / 100 / $cost - 1) * 100 : null;
                    @endphp
                    <tr>
                        <td><input class="form-control form-control-sm" form="pk-{{ $p->id }}" name="name" value="{{ $p->name }}" maxlength="80" aria-label="Nome"></td>
                        <td><input class="form-control form-control-sm" form="pk-{{ $p->id }}" name="tokens" inputmode="numeric" value="{{ $i($p->tokens) }}" aria-label="Tokens"></td>
                        <td><input class="form-control form-control-sm" form="pk-{{ $p->id }}" name="price" inputmode="decimal" value="{{ $m($p->price_cents / 100) }}" aria-label="Preço"></td>
                        <td><input class="form-control form-control-sm" form="pk-{{ $p->id }}" name="validity_months" inputmode="numeric" value="{{ $p->validity_months }}" aria-label="Validade"></td>
                        <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" form="pk-{{ $p->id }}" name="active" value="1" @checked($p->active) aria-label="Ativo"></div></td>
                        <td class="text-end">{{ $cost === null ? '—' : 'R$ '.$m($cost) }}</td>
                        <td class="text-end">{{ $floor === null ? '—' : 'R$ '.$m($floor) }}</td>
                        <td class="text-end {{ $margin !== null && $margin < 0 ? 'text-danger' : '' }}">{{ $margin === null ? '—' : $m($margin, 0).'%' }}</td>
                        <td class="text-end"><form id="pk-{{ $p->id }}" method="post" action="{{ route('admin.packages.update', $p) }}">@csrf @method('PUT')<button class="btn btn-outline-primary btn-sm">Salvar</button></form></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <h6 class="mt-3">Novo pacote</h6>
        <form method="post" action="{{ route('admin.packages.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3"><label class="form-label" for="n-name">Nome</label><input id="n-name" class="form-control" name="name" maxlength="80" value="{{ old('name') }}" required>@error('name')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><label class="form-label" for="n-tokens">Tokens</label><input id="n-tokens" class="form-control" name="tokens" inputmode="numeric" value="{{ old('tokens') }}" placeholder="1.000.000" required>@error('tokens')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><label class="form-label" for="n-price">Preço (R$)</label><input id="n-price" class="form-control" name="price" inputmode="decimal" value="{{ old('price') }}" placeholder="29,90" required>@error('price')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><label class="form-label" for="n-val">Validade (meses)</label><input id="n-val" class="form-control" name="validity_months" inputmode="numeric" value="{{ old('validity_months', 12) }}" required>@error('validity_months')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="n-active" name="active" value="1" checked><label class="form-check-label" for="n-active">Ativo</label></div></div>
            <div class="col-md-1"><button class="btn btn-primary w-100">Criar</button></div>
        </form>
        <p class="text-secondary small mt-2 mb-0">Preço mínimo = custo × (1 + margem) × (1 + taxas). Você pode cobrar mais: o preço é o valor que o cliente percebe, e o custo da IA costuma ser uma fração pequena dele.</p>
    </div>
</div>

@if ($decided->isNotEmpty())
<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-history me-1"></i> Últimos pedidos decididos</h6></div>
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Empresa</th><th>Pacote</th><th class="text-end">Preço</th><th>Situação</th><th>Quando</th><th>Por</th></tr></thead>
            <tbody>
            @foreach ($decided as $o)
                <tr><td>{{ $o->tenant?->name }}</td><td>{{ $o->name }}</td><td class="text-end">R$ {{ $m($o->price_cents / 100) }}</td><td>{{ $status[$o->status] ?? $o->status }}</td><td>{{ $o->decided_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td><td class="text-secondary small">{{ $o->decided_by }}@if ($o->note) · {{ $o->note }}@endif</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
