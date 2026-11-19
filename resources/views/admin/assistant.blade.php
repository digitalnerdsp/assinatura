@extends('layouts.app')
@section('title', 'Assistente no WhatsApp')
@section('section', 'Plataforma')
@section('content')
@php
    $ok = fn (bool $v) => $v ? '<span class="badge bg-success">configurado</span>' : '<span class="badge bg-secondary">não configurado</span>';
@endphp
<div class="alert alert-info border-0">
    Endereço do webhook para colar no painel da Meta (WhatsApp ▸ Configuração): <strong class="text-break">{{ $webhookUrl }}</strong><br>
    Os segredos ficam cifrados e <strong>nunca</strong> são mostrados de novo: deixar o campo em branco mantém o valor salvo.
</div>

<form method="post" action="{{ route('admin.assistant.update') }}" class="row">
    @csrf @method('PUT')
    <div class="col-12 col-xl-6">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bxl-whatsapp me-1"></i> Meta (WhatsApp Cloud API)</h6></div>
            <div class="card-body row g-3">
                <div class="col-12"><label class="form-label" for="a-token">Token de acesso {!! $ok($status['access_token']) !!}</label><input id="a-token" class="form-control" type="password" name="access_token" autocomplete="new-password" placeholder="deixe em branco para manter"></div>
                <div class="col-12"><label class="form-label" for="a-secret">Segredo do app (assinatura do webhook) {!! $ok($status['app_secret']) !!}</label><input id="a-secret" class="form-control" type="password" name="app_secret" autocomplete="new-password" placeholder="deixe em branco para manter"></div>
                <div class="col-12"><label class="form-label" for="a-verify">Token de verificação do webhook {!! $ok($status['verify_token']) !!}</label><input id="a-verify" class="form-control" type="password" name="verify_token" autocomplete="new-password" placeholder="deixe em branco para manter"></div>
                <div class="col-md-6"><label class="form-label" for="a-pnid">Identificador do número (phone_number_id)</label><input id="a-pnid" class="form-control" name="phone_number_id" value="{{ old('phone_number_id', $phoneNumberId) }}"></div>
                <div class="col-md-6"><label class="form-label" for="a-phone">Número que o usuário vê (com DDI)</label><input id="a-phone" class="form-control" name="display_phone" value="{{ old('display_phone', $displayPhone) }}" placeholder="+55 11 90000-0000">@error('display_phone')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="a-waba">Identificador da conta (WABA)</label><input id="a-waba" class="form-control" name="waba_id" value="{{ old('waba_id') }}" placeholder="opcional"></div>
                <div class="col-md-6"><label class="form-label" for="a-ver">Versão da Graph API</label><input id="a-ver" class="form-control" name="graph_version" value="{{ old('graph_version', $graphVersion) }}">@error('graph_version')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-12"><label class="form-label" for="a-rates">Preço por categoria de mensagem (nome=valor, um por linha)</label><textarea id="a-rates" class="form-control" rows="4" name="meta_rates" placeholder="utility=0.04&#10;marketing=0.35">{{ old('meta_rates', $rates) }}</textarea>@error('meta_rates')<div class="text-danger small">{{ $message }}</div>@enderror<div class="form-text">A Meta avisa a categoria e se cobra, mas não o valor: ele vem desta tabela e é conferido todo mês com a fatura real.</div></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-bot me-1"></i> IA (OpenAI) e cobrança</h6></div>
            <div class="card-body row g-3">
                <div class="col-12"><label class="form-label" for="a-key">Chave da API da OpenAI {!! $ok($status['openai']) !!}</label><input id="a-key" class="form-control" type="password" name="api_key" autocomplete="new-password" placeholder="deixe em branco para manter"></div>
                <div class="col-md-6"><label class="form-label" for="a-model">Modelo</label><input id="a-model" class="form-control" name="model" value="{{ old('model', $model) }}">@error('model')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="a-cur">Moeda</label><input id="a-cur" class="form-control" name="currency" value="{{ old('currency', $s->currency()) }}" maxlength="3">@error('currency')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-12"><label class="form-label" for="a-weights">Peso de cada modelo (modelo=peso, um por linha)</label><textarea id="a-weights" class="form-control" rows="3" name="model_weights" placeholder="gpt-5.6-luna=1">{{ old('model_weights', $weights) }}</textarea>@error('model_weights')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="a-markup">Acréscimo sobre o valor da Meta (%)</label><input id="a-markup" class="form-control" name="markup_percent" inputmode="decimal" value="{{ old('markup_percent', $s->markupPercent()) }}">@error('markup_percent')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="a-pack">Pacote de tokens do plano (padrão, por mês)</label><input id="a-pack" class="form-control" name="default_package_tokens" inputmode="numeric" value="{{ old('default_package_tokens', $s->defaultPackageTokens()) }}">@error('default_package_tokens')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="a-over">Excesso permitido para terminar a conversa (tokens)</label><input id="a-over" class="form-control" name="overrun_cap" inputmode="numeric" value="{{ old('overrun_cap', $s->overrunCap()) }}">@error('overrun_cap')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="a-val">Validade dos pacotes comprados (meses)</label><input id="a-val" class="form-control" name="purchase_validity_months" inputmode="numeric" value="{{ old('purchase_validity_months', $s->purchaseValidityMonths()) }}">@error('purchase_validity_months')<div class="text-danger small">{{ $message }}</div>@enderror</div>
                <div class="col-md-6"><label class="form-label" for="a-out">Peso da saída (× entrada)</label><input id="a-out" class="form-control" name="output_factor" inputmode="decimal" value="{{ old('output_factor', $s->outputFactor()) }}"></div>
                <div class="col-md-6"><label class="form-label" for="a-cache">Peso da entrada em cache</label><input id="a-cache" class="form-control" name="cached_factor" inputmode="decimal" value="{{ old('cached_factor', $s->cachedFactor()) }}"></div>
                <div class="col-12"><button class="btn btn-primary">Salvar</button></div>
            </div>
        </div>
    </div>
</form>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-receipt me-1"></i> Lançamento manual</h6></div>
    <div class="card-body">
        <p class="text-secondary small">Enquanto a cobrança automática não existe: lance o pacote comprado, um ajuste de saldo ou o repasse da Meta. A nota e o seu e-mail ficam registrados.</p>
        <form method="post" action="{{ route('admin.assistant.entries') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-4"><label class="form-label" for="e-tenant">Empresa</label><select id="e-tenant" class="form-select" name="tenant_id" required>@foreach ($tenants as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>@error('tenant_id')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><label class="form-label" for="e-kind">Tipo</label><select id="e-kind" class="form-select" name="kind"><option value="purchase">Pacote comprado</option><option value="adjust">Ajuste de saldo (+/−)</option><option value="meta">Repasse da Meta</option></select>@error('kind')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><label class="form-label" for="e-tokens">Tokens</label><input id="e-tokens" class="form-control" name="tokens" inputmode="numeric">@error('tokens')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><label class="form-label" for="e-amount">Valor (repasse)</label><input id="e-amount" class="form-control" name="amount" inputmode="decimal" placeholder="12,50">@error('amount')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-8"><label class="form-label" for="e-note">Nota (obrigatória)</label><input id="e-note" class="form-control" name="note" maxlength="190" required>@error('note')<div class="text-danger small">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><button class="btn btn-outline-primary">Lançar</button></div>
        </form>
    </div>
</div>
@endsection
