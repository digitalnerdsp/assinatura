@extends('layouts.app')
@section('title', 'Assistente no WhatsApp')
@section('section', 'Assistente')
@section('content')
@php
    $n = fn ($v) => number_format((float) $v, 0, ',', '.');
    $m = fn ($v) => number_format((float) $v, 2, ',', '.');
@endphp
<div class="row">
    <div class="col-12 col-xl-7">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bxl-whatsapp me-1"></i> Assistente no WhatsApp</h6></div>
            <div class="card-body">
                <p class="text-secondary">Cada pessoa vincula o próprio número em <a href="{{ route('profile.edit') }}">Meu perfil</a> e conversa com o assistente para consultar documentos, ver quem falta assinar e receber o PDF assinado.</p>
                <form method="post" action="{{ route('assistant.update') }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="users_present" value="1">
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="enabled" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="ast-on" name="enabled" value="1" @checked($tenant->assistant_enabled)>
                        <label class="form-check-label" for="ast-on">Assistente ligado na empresa</label>
                    </div>

                    <h6>O que ele pode fazer</h6>
                    @foreach ($groups as $key => $g)
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="ast-g-{{ $key }}" name="groups[]" value="{{ $key }}" @checked(in_array($key, $enabledGroups, true))><label class="form-check-label" for="ast-g-{{ $key }}">{{ $g['label'] }}</label></div>
                    @endforeach
                    @error('groups.*')<div class="text-danger small">{{ $message }}</div>@enderror

                    <h6 class="mt-3">Quem pode usar</h6>
                    @foreach ($users as $u)
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="ast-u-{{ $u->id }}" name="users[]" value="{{ $u->id }}" @checked($u->assistant_enabled)><label class="form-check-label" for="ast-u-{{ $u->id }}">{{ $u->name }} <span class="text-secondary small">{{ $u->email }}{{ $u->whatsapp_verified_at ? ' · WhatsApp vinculado' : '' }}</span></label></div>
                    @endforeach

                    <button class="btn btn-primary mt-3">Salvar</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-bar-chart-alt-2 me-1"></i> Consumo de {{ \Carbon\CarbonImmutable::createFromFormat('Y-m', $period)->locale('pt_BR')->translatedFormat('F/Y') }}</h6></div>
            <div class="card-body">
                <div class="mb-3"><div class="text-secondary small">Saldo de tokens</div><div class="fs-4">{{ $n($balance) }}</div></div>
                <div class="mb-3"><div class="text-secondary small">Tokens usados no mês</div><div class="fs-4">{{ $n($usedTokens) }}</div></div>
                <hr>
                <div class="text-secondary small">Mensagens da Meta no mês</div>
                <div>{{ $metaTotal['count'] }} mensagem(ns)</div>
                <div class="text-secondary small mt-2">Valor da Meta: {{ $currency }} {{ $m($metaTotal['meta']) }}</div>
                <div>Valor cobrado do seu saldo no mês: <strong>{{ $currency }} {{ $m($metaTotal["charged"]) }}</strong></div>
                <p class="text-secondary small mt-2 mb-0">O custo das mensagens do WhatsApp é repassado com acréscimo de {{ rtrim(rtrim(number_format($markup, 2, ',', ''), '0'), ',') }}%.</p>
            </div>
        </div>
        <div class="card radius-10 mt-3">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-wallet me-1"></i> Saldo para avisos no WhatsApp</h6></div>
            <div class="card-body">
                @if ($credit['noBalance'])
                    <div class="alert alert-warning border-0">Sem saldo: os avisos no WhatsApp estão parados. {{ $credit['postpaid'] ? 'O limite do pós-pago acabou; fale com o suporte.' : 'Fale com o suporte para recarregar.' }}</div>
                @endif
                <div class="mb-2"><div class="text-secondary small">Saldo</div><div class="fs-4 {{ $credit['balance'] < 0 ? 'text-danger' : '' }}">R$ {{ $m($credit['balance']) }}</div></div>
                <div class="text-secondary small">Modo: <strong>{{ $credit['postpaid'] ? 'pós-pago (somado à mensalidade, limite de R$ '.$m($credit['limit']).')' : 'pré-pago' }}</strong></div>
                <div class="text-secondary small">Gasto no mês: R$ {{ $m($credit['month']['usage']) }} em {{ $credit['month']['messages'] }} mensagem(ns)</div>
            </div>
        </div>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-package me-1"></i> Pacotes de tokens</h6></div>
    <div class="card-body">
        @error('package')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
        @if ($packages->isEmpty())
            <p class="text-secondary mb-0">Nenhum pacote disponível no momento. Fale com o suporte.</p>
        @else
            <p class="text-secondary small">Precisa de mais tokens? Peça um pacote: o pagamento é confirmado pelo suporte e os tokens entram no seu saldo.</p>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>Pacote</th><th class="text-end">Tokens</th><th class="text-end">Conversas (estimativa)</th><th class="text-end">Preço</th><th>Validade</th><th></th></tr></thead>
                <tbody>
                @foreach ($packages as $p)
                    <tr>
                        <td>{{ $p->name }}</td><td class="text-end">{{ number_format($p->tokens, 0, ',', '.') }}</td>
                        <td class="text-end">≈ {{ number_format(intdiv($p->tokens, max(1, $tokensPerConversation)), 0, ',', '.') }} conversas</td>
                        <td class="text-end">R$ {{ number_format($p->price_cents / 100, 2, ',', '.') }}</td><td>{{ $p->validity_months }} meses</td>
                        <td class="text-end"><form method="post" action="{{ route('assistant.packages.order', $p) }}" data-confirm="Pedir o pacote {{ $p->name }} por R$ {{ number_format($p->price_cents / 100, 2, ',', '.') }}?">@csrf<button class="btn btn-primary btn-sm">Pedir</button></form></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
        @if ($orders->isNotEmpty())
            <h6 class="mt-3">Seus pedidos</h6>
            <ul class="list-unstyled mb-0">
                @foreach ($orders as $o)
                    <li class="d-flex justify-content-between align-items-center border-top py-2">
                        <span>{{ $o->name }} · R$ {{ number_format($o->price_cents / 100, 2, ',', '.') }} · {{ ['pending' => 'pedido aguardando liberação', 'approved' => 'liberado', 'rejected' => 'recusado', 'canceled' => 'cancelado'][$o->status] ?? $o->status }}@if ($o->status === 'rejected' && $o->note) <span class="text-secondary small">({{ $o->note }})</span>@endif</span>
                        @if ($o->status === 'pending')<form method="post" action="{{ route('assistant.orders.cancel', $o) }}" data-confirm="Cancelar este pedido?">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">Cancelar</button></form>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
