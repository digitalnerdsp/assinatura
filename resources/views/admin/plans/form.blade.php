@extends('layouts.app')
@section('title', $plan->exists ? 'Editar plano' : 'Novo plano')
@section('section', 'Plataforma')
@section('content')
@php
    $o = fn (string $key, $default = null) => old($key, $default);
    $lim = $plan->limits ?? [];
    $trial = $plan->trial ?? [];
    $con = $plan->consumption ?? [];
    $feat = $plan->features ?? [];
    $cents = fn ($c) => $c === null ? '' : number_format($c / 100, 2, ',', '');
    $sel = fn ($a, $b) => (string) $a === (string) $b ? 'selected' : '';
@endphp
@error('plan')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
<form method="post" action="{{ $plan->exists ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" class="row g-3">
    @csrf
    @if ($plan->exists) @method('PUT') @endif

    <div class="col-12">
        <div class="card radius-10"><div class="card-body row g-2">
            <h5 class="mb-1">Dados do plano</h5>
            <div class="col-12 col-md-6"><label class="form-label" for="f-name">Nome</label><input class="form-control @error('name') is-invalid @enderror" id="f-name" name="name" maxlength="120" required value="{{ $o('name', $plan->name) }}">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-6 col-md-3"><label class="form-label" for="f-pos">Ordem na lista</label><input type="number" min="0" class="form-control" id="f-pos" name="position" value="{{ $o('position', $plan->position ?? 0) }}"></div>
            <div class="col-12"><label class="form-label" for="f-desc">Descrição</label><textarea class="form-control" id="f-desc" name="description" rows="2" maxlength="1000">{{ $o('description', $plan->description) }}</textarea></div>
            <div class="col-12 d-flex flex-wrap gap-4">
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="f-active" name="active" value="1" @checked($o('active', $plan->active ?? true))><label class="form-check-label" for="f-active">Ativo</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="f-public" name="is_public" value="1" @checked($o('is_public', $plan->is_public ?? true))><label class="form-check-label" for="f-public">Visível na página de preços</label></div>
            </div>
        </div></div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card radius-10 h-100"><div class="card-body row g-2">
            <h5 class="mb-1">Limites</h5>
            <p class="text-secondary small">Deixe em branco para <strong>ilimitado</strong>.</p>
            <div class="col-6"><label class="form-label" for="l-docs">Documentos por período</label><input class="form-control" id="l-docs" name="limits[documents]" inputmode="numeric" value="{{ $o('limits.documents', $lim['documents'] ?? '') }}"></div>
            <div class="col-6"><label class="form-label" for="l-users">Usuários</label><input class="form-control" id="l-users" name="limits[users]" inputmode="numeric" value="{{ $o('limits.users', $lim['users'] ?? '') }}"></div>
            <div class="col-6"><label class="form-label" for="l-sto">Armazenamento (MB)</label><input class="form-control" id="l-sto" name="limits[storage_mb]" inputmode="numeric" value="{{ $o('limits.storage_mb', $lim['storage_mb'] ?? '') }}"><div class="form-text">1 GB = 1024 MB</div></div>
            <div class="col-6"><label class="form-label" for="l-pdf">Tamanho máximo do PDF (MB)</label><input class="form-control" id="l-pdf" name="limits[max_pdf_mb]" inputmode="numeric" value="{{ $o('limits.max_pdf_mb', $lim['max_pdf_mb'] ?? '') }}"><div class="form-text">O teto do servidor ({{ (int) config('signing.max_pdf_mb') }} MB) vale primeiro.</div></div>
        </div></div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card radius-10 h-100"><div class="card-body row g-2">
            <h5 class="mb-1">Teste grátis</h5>
            <p class="text-secondary small">0 ou vazio = sem teste. A empresa nova começa em teste por estes dias.</p>
            <div class="col-6"><label class="form-label" for="t-days">Dias de teste</label><input class="form-control" id="t-days" name="trial[days]" inputmode="numeric" value="{{ $o('trial.days', $trial['days'] ?? '') }}"></div>
            <div class="col-6"><label class="form-label" for="t-end">Ao fim do teste</label>
                <select class="form-select" id="t-end" name="trial[on_end]">
                    @foreach (['block' => 'Bloquear novos documentos', 'convert' => 'Virar plano pago (Fase 4)', 'free_plan' => 'Cair para plano gratuito (Fase 4)'] as $k => $label)<option value="{{ $k }}" {{ $sel($o('trial.on_end', $trial['on_end'] ?? 'block'), $k) }}>{{ $label }}</option>@endforeach
                </select></div>
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="t-pay" name="trial[requires_payment]" value="1" @checked($o('trial.requires_payment', $trial['requires_payment'] ?? false))><label class="form-check-label" for="t-pay">Pede forma de pagamento para começar o teste</label></div></div>
            <div class="col-6"><label class="form-label" for="t-docs">Limite de documentos no teste</label><input class="form-control" id="t-docs" name="trial[limits][documents]" inputmode="numeric" value="{{ $o('trial.limits.documents', $trial['limits']['documents'] ?? '') }}"></div>
            <div class="col-6"><label class="form-label" for="t-users">Limite de usuários no teste</label><input class="form-control" id="t-users" name="trial[limits][users]" inputmode="numeric" value="{{ $o('trial.limits.users', $trial['limits']['users'] ?? '') }}"></div>
        </div></div>
    </div>

    <div class="col-12">
        <div class="card radius-10"><div class="card-body row g-2">
            <h5 class="mb-1">Regras de consumo de documentos</h5>
            <div class="col-12 col-md-4"><label class="form-label" for="c-when">Quando desconta</label>
                <select class="form-select" id="c-when" name="consumption[when]">@foreach (['send' => 'Ao enviar (padrão)', 'complete' => 'Ao concluir (todos assinaram)'] as $k => $label)<option value="{{ $k }}" {{ $sel($o('consumption.when', $con['when'] ?? 'send'), $k) }}>{{ $label }}</option>@endforeach</select></div>
            <div class="col-12 col-md-4"><label class="form-label" for="c-bal">Tipo de saldo</label>
                <select class="form-select" id="c-bal" name="consumption[balance]">@foreach (['quota' => 'Cota que renova a cada período', 'credits' => 'Pacote de créditos (não renova)', 'both' => 'Os dois (cota primeiro, depois créditos)'] as $k => $label)<option value="{{ $k }}" {{ $sel($o('consumption.balance', $con['balance'] ?? 'quota'), $k) }}>{{ $label }}</option>@endforeach</select></div>
            <div class="col-12 col-md-4"><label class="form-label" for="c-empty">Ao acabar o saldo</label>
                <select class="form-select" id="c-empty" name="consumption[on_empty]">@foreach (['block' => 'Bloquear novos documentos', 'overage' => 'Cobrar o excedente por documento', 'cap' => 'Liberar até um teto de excedente'] as $k => $label)<option value="{{ $k }}" {{ $sel($o('consumption.on_empty', $con['on_empty'] ?? 'block'), $k) }}>{{ $label }}</option>@endforeach</select></div>
            <div class="col-6 col-md-4"><label class="form-label" for="c-quota">Cota por período (se diferente do limite)</label><input class="form-control" id="c-quota" name="consumption[quota_amount]" inputmode="numeric" value="{{ $o('consumption.quota_amount', $con['quota_amount'] ?? '') }}"></div>
            <div class="col-6 col-md-4"><label class="form-label" for="c-over">Valor do excedente (R$ por documento)</label><input class="form-control" id="c-over" name="consumption[overage]" inputmode="decimal" placeholder="2,50" value="{{ $o('consumption.overage', isset($con['overage_cents']) ? $cents($con['overage_cents']) : '') }}"></div>
            <div class="col-6 col-md-4"><label class="form-label" for="c-cap">Teto de excedente (documentos)</label><input class="form-control" id="c-cap" name="consumption[cap]" inputmode="numeric" value="{{ $o('consumption.cap', $con['cap'] ?? '') }}"></div>
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="c-refund" name="consumption[refund_unsigned]" value="1" @checked($o('consumption.refund_unsigned', $con['refund_unsigned'] ?? false))><label class="form-check-label" for="c-refund">Devolver ao saldo o documento cancelado ou expirado sem nenhuma assinatura</label></div></div>
        </div></div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card radius-10 h-100"><div class="card-body">
            <h5 class="mb-1">Recursos</h5>
            <p class="text-secondary small">Os marcados com <span class="badge bg-info">ainda informativo</span> são gravados no plano, mas o sistema ainda não os barra.</p>
            <div class="row g-2">
                @foreach (\App\Models\Plan::FEATURE_LABELS as $key => $label)
                    <div class="col-12 col-sm-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="ft-{{ $key }}" name="features[{{ $key }}]" value="1" @checked(old('features') !== null ? ! empty(old('features.'.$key)) : ($feat[$key] ?? false))><label class="form-check-label" for="ft-{{ $key }}">{{ $label }}@unless (in_array($key, \App\Models\Plan::FEATURES_ENFORCED, true)) <span class="badge bg-info">ainda informativo</span>@endunless</label></div></div>
                @endforeach
            </div>
        </div></div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card radius-10 h-100"><div class="card-body">
            <h5 class="mb-1">Preços por período</h5>
            <p class="text-secondary small">Em reais, sem negativo. Deixe o valor em branco para não oferecer o período. Todos funcionam igual: uma fatura do período inteiro (a cobrança automática vem na Fase 4).</p>
            @foreach (\App\Models\PlanPrice::PERIODS as $period => $label)
                @php($price = $prices[$period] ?? null)
                <div class="row g-2 align-items-end mb-2">
                    <div class="col-12 col-sm-4"><label class="form-label" for="p-{{ $period }}">{{ $label }} (R$)</label><input class="form-control" id="p-{{ $period }}" name="prices[{{ $period }}][amount]" inputmode="decimal" placeholder="0,00" value="{{ $o('prices.'.$period.'.amount', $price ? $cents($price->amount_cents) : '') }}"></div>
                    <div class="col-8 col-sm-5"><label class="form-label" for="pd-{{ $period }}">Rótulo de desconto</label><input class="form-control" id="pd-{{ $period }}" name="prices[{{ $period }}][discount_label]" maxlength="60" value="{{ $o('prices.'.$period.'.discount_label', $price?->discount_label) }}"></div>
                    <div class="col-4 col-sm-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="pa-{{ $period }}" name="prices[{{ $period }}][active]" value="1" @checked(old('prices') !== null ? ! empty(old('prices.'.$period.'.active')) : ($price->active ?? true))><label class="form-check-label" for="pa-{{ $period }}">Ativo</label></div></div>
                </div>
            @endforeach
        </div></div>
    </div>

    <div class="col-12 d-flex flex-wrap gap-2">
        <button class="btn btn-primary drive-tap">Salvar plano</button>
        <a class="btn btn-outline-secondary drive-tap" href="{{ route('admin.plans.index') }}">Cancelar</a>
    </div>
</form>
@endsection
