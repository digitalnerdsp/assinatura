@extends('layouts.app')
@section('title', 'Avisos do WhatsApp')
@section('section', 'Plataforma')
@section('content')
@php
    $badge = ['rascunho' => 'secondary', 'enviado' => 'info', 'aprovado' => 'success', 'rejeitado' => 'danger', 'pausado' => 'warning'];
@endphp
@if (session('error'))<div class="alert alert-danger border-0">{{ session('error') }}</div>@endif
<div class="alert alert-info border-0">
    Cada aviso é uma mensagem de <strong>modelo</strong>, que a Meta precisa aprovar antes do envio (é a única forma de falar com o usuário fora da janela de 24 horas).
    Escreva o texto, clique em <strong>Enviar para aprovação</strong> e depois em <strong>Atualizar status</strong>. Só modelos <strong>aprovados</strong> e ligados enviam.
    Mantenha o tom de aviso (sem oferta nem convite) para a Meta aprovar como "utilidade", que é a categoria mais barata. O custo cobrado vem da categoria que a Meta aplicar.
    @unless ($hasWaba)<br><strong>Falta o identificador da conta (WABA)</strong> em Admin ▸ Assistente WhatsApp para enviar modelos.@endunless
</div>
<div class="d-flex justify-content-end mb-3">
    <form method="post" action="{{ route('admin.notifications.sync') }}">@csrf<button class="btn btn-outline-primary btn-sm">Atualizar status na Meta</button></form>
</div>
<div class="row">
@foreach ($catalog as $key => $def)
    @php $t = $templates[$key]; @endphp
    <div class="col-12 col-xl-6">
        <div class="card radius-10">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">{{ $def['label'] }}</h6>
                <span class="badge bg-{{ $badge[$t->status] ?? 'secondary' }}">{{ $t->status }}</span>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-2">{{ $def['when'] }}</p>
                <form method="post" action="{{ route('admin.notifications.update', $key) }}" class="row g-2">
                    @csrf @method('PUT')
                    <div class="col-md-8"><label class="form-label" for="n-{{ $key }}-name">Nome do modelo na Meta</label><input id="n-{{ $key }}-name" class="form-control" name="meta_name" value="{{ old('meta_name', $t->meta_name) }}">@error('meta_name')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                    <div class="col-md-4"><label class="form-label" for="n-{{ $key }}-lang">Idioma</label><input id="n-{{ $key }}-lang" class="form-control" name="language" value="{{ old('language', $t->language) }}">@error('language')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                    <div class="col-12">
                        <label class="form-label" for="n-{{ $key }}-body">Texto</label>
                        <textarea id="n-{{ $key }}-body" class="form-control" rows="4" name="body">{{ old('body', $t->body) }}</textarea>
                        @error('body')<div class="text-danger small">{{ $message }}</div>@enderror
                        <div class="form-text">Variáveis: @foreach ($def['vars'] as $i => $v)<code>&#123;&#123;{{ $i + 1 }}&#125;&#125;</code> {{ $v }}@if (! $loop->last), @endif @endforeach</div>
                    </div>
                    <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="n-{{ $key }}-on" name="enabled" value="1" @checked($t->enabled) @disabled($t->status !== 'aprovado')><label class="form-check-label" for="n-{{ $key }}-on">Ligado</label></div>
                        <span class="text-secondary small">Categoria pela Meta: {{ $t->category }}</span>
                        <button class="btn btn-primary btn-sm ms-auto">Salvar</button>
                    </div>
                    @if ($t->status === 'rejeitado' && $t->rejected_reason)<div class="col-12 text-danger small">Rejeitado pela Meta: {{ $t->rejected_reason }}</div>@endif
                </form>
                @if (in_array($t->status, ['rascunho', 'rejeitado'], true))
                    <form method="post" action="{{ route('admin.notifications.submit', $key) }}" class="mt-2">@csrf<button class="btn btn-outline-success btn-sm">Enviar para aprovação</button></form>
                @endif
            </div>
        </div>
    </div>
@endforeach
</div>

@php $m = fn ($v) => number_format((float) $v, 2, ',', '.'); @endphp
<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-time-five me-1"></i> Documento parado</h6></div>
    <div class="card-body">
        <form method="post" action="{{ route('admin.notifications.settings') }}" class="row g-2 align-items-end">
            @csrf @method('PUT')
            <div class="col-md-4"><label class="form-label" for="s-days">Avisar quando ficar sem nova assinatura por (dias)</label><input id="s-days" class="form-control" name="stalled_days" inputmode="numeric" value="{{ old('stalled_days', $stalledDays) }}">@error('stalled_days')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><button class="btn btn-primary">Salvar</button></div>
        </form>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-wallet me-1"></i> Cobrança dos avisos por empresa</h6></div>
    <div class="card-body">
        <p class="text-secondary small">O que a Meta cobra por aviso, com o acréscimo, sai do saldo da empresa. <strong>Pré-pago:</strong> só envia com saldo. <strong>Pós-pago:</strong> envia até o limite e o valor é somado à mensalidade; depois de cobrar, lance a <em>quitação</em>. A empresa de um super administrador não é cobrada.</p>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Empresa</th><th>Modo</th><th>Limite (pós-pago)</th><th class="text-end">Saldo</th><th></th></tr></thead>
                <tbody>
                @foreach ($tenants as $t)
                    @php $bal = (float) ($balances[$t->id] ?? 0); @endphp
                    <tr>
                        <td>{{ $t->name }}</td>
                            <td><select class="form-select form-select-sm" form="bf-{{ $t->id }}" name="wa_billing_mode" aria-label="Modo de cobrança de {{ $t->name }}"><option value="prepaid" @selected($t->wa_billing_mode === 'prepaid')>Pré-pago</option><option value="postpaid" @selected($t->wa_billing_mode === 'postpaid')>Pós-pago</option></select></td>
                            <td><input class="form-control form-control-sm" form="bf-{{ $t->id }}" name="wa_credit_limit" inputmode="decimal" value="{{ $t->wa_credit_limit !== null ? $m($t->wa_credit_limit) : '' }}" placeholder="0,00" aria-label="Limite de {{ $t->name }}"></td>
                            <td class="text-end {{ $bal < 0 ? 'text-danger' : '' }}">R$ {{ $m($bal) }}</td>
                            <td class="text-end"><form id="bf-{{ $t->id }}" method="post" action="{{ route('admin.notifications.billing', $t) }}">@csrf @method('PUT')<button class="btn btn-outline-primary btn-sm">Salvar</button></form></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-receipt me-1"></i> Lançamento no saldo</h6></div>
    <div class="card-body">
        <form method="post" action="{{ route('admin.notifications.credit') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-4"><label class="form-label" for="c-tenant">Empresa</label><select id="c-tenant" class="form-select" name="tenant_id" required>@foreach ($tenants as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>@error('tenant_id')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-3"><label class="form-label" for="c-kind">Tipo</label><select id="c-kind" class="form-select" name="kind"><option value="topup">Recarga</option><option value="settle">Quitação do pós-pago</option><option value="adjust">Ajuste (+/−)</option></select></div>
            <div class="col-md-2"><label class="form-label" for="c-amount">Valor (R$)</label><input id="c-amount" class="form-control" name="amount" inputmode="decimal" placeholder="20,00">@error('amount')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-8"><label class="form-label" for="c-note">Nota (obrigatória)</label><input id="c-note" class="form-control" name="note" maxlength="190" required>@error('note')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><button class="btn btn-outline-primary">Lançar</button></div>
        </form>
        @if ($entries->isNotEmpty())
            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Quando</th><th>Empresa</th><th>Tipo</th><th class="text-end">Valor</th><th>Nota</th></tr></thead>
                    <tbody>
                    @foreach ($entries as $e)
                        <tr><td>{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td><td>{{ $tenants->firstWhere('id', $e->tenant_id)?->name }}</td><td>{{ ['topup' => 'Recarga', 'settle' => 'Quitação', 'adjust' => 'Ajuste'][$e->kind] ?? $e->kind }}</td><td class="text-end">R$ {{ $m($e->amount) }}</td><td class="text-secondary small">{{ $e->note }}@if ($e->created_by) · {{ $e->created_by }}@endif</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
