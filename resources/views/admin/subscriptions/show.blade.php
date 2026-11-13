@extends('layouts.app')
@section('title', 'Assinatura de '.$tenant->name)
@section('section', 'Plataforma')
@section('actions')<a class="btn btn-outline-secondary drive-tap" href="{{ route('admin.tenants.show', $tenant) }}"><i class="bx bx-buildings"></i> Dados da empresa</a>@endsection
@section('content')
@php
    $ov = $sub->overrides ?? [];
    $ovl = $ov['limits'] ?? [];
    $ovf = $ov['features'] ?? [];
    $ovc = $ov['consumption'] ?? [];
    $planLimits = $sub->plan->limits ?? [];
    $cents = fn ($c) => $c === null ? '' : number_format($c / 100, 2, ',', '');
    $sel = fn ($a, $b) => (string) $a === (string) $b ? 'selected' : '';
    $labels = ['trialing' => 'Em teste', 'active' => 'Ativa', 'blocked' => 'Bloqueada', 'canceled' => 'Cancelada'];
    $limitNames = ['documents' => 'Documentos por período', 'users' => 'Usuários', 'storage_mb' => 'Armazenamento (MB)', 'max_pdf_mb' => 'Tamanho máximo do PDF (MB)'];
    $bytes = fn (int $b) => $b >= 1073741824 ? number_format($b / 1073741824, 2, ',', '.').' GB' : number_format($b / 1048576, 1, ',', '.').' MB';
@endphp
@error('plan')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror

<div class="row g-3">
    <div class="col-12 col-lg-4">
        <div class="card radius-10 h-100"><div class="card-body">
            <h5 class="mb-1">{{ $tenant->name }}</h5>
            <p class="text-secondary small mb-3">Plano atual: <strong>{{ $sub->plan->name }}</strong> · {{ $labels[$sub->status] }}@if ($sub->billing_exempt) · isenta de cobrança @endif</p>
            <ul class="list-unstyled mb-0">
                <li class="mb-2"><strong>Documentos no período:</strong> {{ $usage['documents']['used'] }}{{ $usage['documents']['limit'] !== null ? ' de '.$usage['documents']['limit'] : ' (sem limite)' }}</li>
                <li class="mb-2"><strong>Créditos:</strong> {{ $usage['documents']['credits'] }}</li>
                <li class="mb-2"><strong>Usuários:</strong> {{ $usage['users']['used'] }}{{ $usage['users']['limit'] !== null ? ' de '.$usage['users']['limit'] : ' (sem limite)' }}</li>
                <li class="mb-2"><strong>Armazenamento:</strong> {{ $bytes($usage['storage']['used_bytes']) }}{{ $usage['storage']['limit_bytes'] !== null ? ' de '.$bytes($usage['storage']['limit_bytes']) : ' (sem limite)' }}</li>
                @if ($sub->period_end)<li><strong>Período até:</strong> {{ $sub->period_end->timezone('America/Sao_Paulo')->format('d/m/Y') }}</li>@endif
            </ul>
        </div></div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card radius-10 h-100"><div class="card-body">
            <h5 class="mb-1">Lançar crédito ou ajuste manual</h5>
            <p class="text-secondary small">Use para registrar um pagamento recebido fora do sistema (PIX direto, por exemplo) ou conceder documentos. Número negativo tira créditos. Fica no extrato.</p>
            <form method="post" action="{{ route('admin.subscriptions.credits', $tenant) }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-4 col-md-3"><label class="form-label" for="cr-delta">Créditos (±)</label><input class="form-control @error('delta') is-invalid @enderror" id="cr-delta" name="delta" inputmode="numeric" required>@error('delta')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-8 col-md-6"><label class="form-label" for="cr-note">Motivo</label><input class="form-control @error('note') is-invalid @enderror" id="cr-note" name="note" maxlength="200" required>@error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-12 col-md-3"><button class="btn btn-primary drive-tap w-100">Lançar</button></div>
            </form>
        </div></div>
    </div>

    <div class="col-12">
        <form method="post" action="{{ route('admin.subscriptions.update', $tenant) }}" class="card radius-10"><div class="card-body row g-3">
            @csrf @method('PUT')
            <h5 class="mb-0">Plano e situação</h5>
            <div class="col-12 col-md-4"><label class="form-label" for="s-plan">Plano</label><select class="form-select" id="s-plan" name="plan_id">@foreach ($plans as $p)<option value="{{ $p->id }}" {{ $sel(old('plan_id', $sub->plan_id), $p->id) }}>{{ $p->name }}{{ $p->active ? '' : ' (inativo)' }}</option>@endforeach</select></div>
            <div class="col-6 col-md-3"><label class="form-label" for="s-status">Situação</label><select class="form-select" id="s-status" name="status">@foreach ($labels as $k => $l)<option value="{{ $k }}" {{ $sel(old('status', $sub->status), $k) }}>{{ $l }}</option>@endforeach</select></div>
            <div class="col-6 col-md-5 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="s-exempt" name="billing_exempt" value="1" @checked(old('billing_exempt', $sub->billing_exempt))><label class="form-check-label" for="s-exempt">Isenta de cobrança (cliente amigo, parceiro, teste)</label></div></div>
            <div class="col-12"><label class="form-label" for="s-notes">Observações internas</label><textarea class="form-control" id="s-notes" name="notes" rows="2" maxlength="1000">{{ old('notes', $sub->notes) }}</textarea></div>

            <h5 class="mb-0 mt-2">Sobrescritas só desta empresa</h5>
            <p class="text-secondary small mb-0">Em branco = vale o que o plano diz. Marque <strong>ilimitado</strong> para tirar o limite só desta empresa.</p>
            @foreach ($limitNames as $key => $label)
                @php($has = array_key_exists($key, $ovl))
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label" for="ol-{{ $key }}">{{ $label }}</label>
                    <input class="form-control" id="ol-{{ $key }}" name="limits[{{ $key }}]" inputmode="numeric" value="{{ $has && $ovl[$key] !== null ? $ovl[$key] : '' }}" placeholder="plano: {{ isset($planLimits[$key]) ? $planLimits[$key] : 'ilimitado' }}">
                    <div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="ou-{{ $key }}" name="unlimited[{{ $key }}]" value="1" @checked($has && $ovl[$key] === null)><label class="form-check-label" for="ou-{{ $key }}">ilimitado</label></div>
                </div>
            @endforeach

            <div class="col-12"><div class="fw-bold mb-1">Recursos</div>
                <div class="row g-2">
                    @foreach (\App\Models\Plan::FEATURE_LABELS as $key => $label)
                        @php($state = array_key_exists($key, $ovf) ? ($ovf[$key] ? 'on' : 'off') : 'inherit')
                        <div class="col-12 col-sm-6 col-lg-4"><label class="form-label small mb-0" for="of-{{ $key }}">{{ $label }} <span class="text-secondary">(plano: {{ ($sub->plan->features[$key] ?? false) ? 'ligado' : 'desligado' }})</span></label>
                            <select class="form-select" id="of-{{ $key }}" name="features[{{ $key }}]"><option value="inherit" {{ $sel($state, 'inherit') }}>Seguir o plano</option><option value="on" {{ $sel($state, 'on') }}>Ligado</option><option value="off" {{ $sel($state, 'off') }}>Desligado</option></select></div>
                    @endforeach
                </div>
            </div>

            <div class="col-12"><div class="fw-bold mb-1">Regras de consumo</div>
                <div class="row g-2">
                    <div class="col-6 col-md-3"><label class="form-label small mb-0" for="oc-when">Quando desconta</label><select class="form-select" id="oc-when" name="consumption[when]"><option value="">Seguir o plano</option><option value="send" {{ $sel($ovc['when'] ?? '', 'send') }}>Ao enviar</option><option value="complete" {{ $sel($ovc['when'] ?? '', 'complete') }}>Ao concluir</option></select></div>
                    <div class="col-6 col-md-3"><label class="form-label small mb-0" for="oc-bal">Tipo de saldo</label><select class="form-select" id="oc-bal" name="consumption[balance]"><option value="">Seguir o plano</option><option value="quota" {{ $sel($ovc['balance'] ?? '', 'quota') }}>Cota</option><option value="credits" {{ $sel($ovc['balance'] ?? '', 'credits') }}>Créditos</option><option value="both" {{ $sel($ovc['balance'] ?? '', 'both') }}>Os dois</option></select></div>
                    <div class="col-6 col-md-3"><label class="form-label small mb-0" for="oc-empty">Ao acabar o saldo</label><select class="form-select" id="oc-empty" name="consumption[on_empty]"><option value="">Seguir o plano</option><option value="block" {{ $sel($ovc['on_empty'] ?? '', 'block') }}>Bloquear</option><option value="overage" {{ $sel($ovc['on_empty'] ?? '', 'overage') }}>Cobrar excedente</option><option value="cap" {{ $sel($ovc['on_empty'] ?? '', 'cap') }}>Liberar até um teto</option></select></div>
                    <div class="col-6 col-md-3"><label class="form-label small mb-0" for="oc-ref">Devolver não assinado</label><select class="form-select" id="oc-ref" name="consumption[refund_unsigned]"><option value="">Seguir o plano</option><option value="on" {{ $sel(array_key_exists('refund_unsigned', $ovc) ? ($ovc['refund_unsigned'] ? 'on' : 'off') : '', 'on') }}>Sim</option><option value="off" {{ $sel(array_key_exists('refund_unsigned', $ovc) ? ($ovc['refund_unsigned'] ? 'on' : 'off') : '', 'off') }}>Não</option></select></div>
                    <div class="col-4"><label class="form-label small mb-0" for="oc-quota">Cota por período</label><input class="form-control" id="oc-quota" name="consumption[quota_amount]" inputmode="numeric" value="{{ $ovc['quota_amount'] ?? '' }}"></div>
                    <div class="col-4"><label class="form-label small mb-0" for="oc-over">Excedente (R$)</label><input class="form-control" id="oc-over" name="consumption[overage]" inputmode="decimal" value="{{ isset($ovc['overage_cents']) ? $cents($ovc['overage_cents']) : '' }}"></div>
                    <div class="col-4"><label class="form-label small mb-0" for="oc-cap">Teto de excedente</label><input class="form-control" id="oc-cap" name="consumption[cap]" inputmode="numeric" value="{{ $ovc['cap'] ?? '' }}"></div>
                </div>
            </div>

            <div class="col-12 col-md-4"><label class="form-label" for="o-price">Preço mensal combinado (R$)</label><input class="form-control" id="o-price" name="price" inputmode="decimal" placeholder="usa o preço do plano" value="{{ isset($ov['price_cents']) ? $cents($ov['price_cents']) : '' }}"><div class="form-text">Vale quando a cobrança for ligada (Fase 4).</div></div>
            <div class="col-12"><button class="btn btn-primary drive-tap">Salvar assinatura</button></div>
        </div></form>
    </div>

    <div class="col-12">
        <div class="card radius-10"><div class="card-body">
            <h5 class="mb-2">Extrato de uso (últimos 50)</h5>
            @include('partials.usage-entries', ['entries' => $entries])
        </div></div>
    </div>
</div>
@endsection
