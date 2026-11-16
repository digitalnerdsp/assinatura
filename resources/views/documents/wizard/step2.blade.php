@extends('documents.wizard.layout')
@section('title', 'Criar documento')
@section('wizard')
@php
    $rows = old('people');
    if ($rows === null) {
        $rows = $people->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'email' => $s->email, 'phone' => $s->phone, 'cpf' => $s->cpf, 'role' => $s->role, 'require_otp' => $s->require_otp, 'require_selfie' => $s->require_selfie, 'require_document' => $s->require_document, 'require_geolocation' => $s->require_geolocation])->all();
    }
    $all = $rows ?: [];
    $signerRows = [];
    $obsRows = [];
    foreach (old('people') !== null ? $all : array_values($all) as $k => $r) {
        if (($r['role'] ?? 'signer') === 'observer') { $obsRows[$k] = $r; } else { $signerRows[$k] = $r; }
    }
    $next = $all ? max(array_keys($all)) + 1 : 0;
    if (! $signerRows) { $signerRows[$next++] = ['name' => '']; }
    $rows = $signerRows;
    $senderOn = old('sender_signs', $hasSender ? 1 : 0);
    $order = old('signing_order', $envelope->signing_order->value);
    $adv = collect($signerRows)->contains(fn ($r) => ! empty($r['email']) || ! empty($r['phone']) || ! empty($r['cpf']) || (($r['role'] ?? 'signer') === 'witness') || ! empty($r['require_otp']) || ! empty($r['require_selfie']) || ! empty($r['require_document']) || ! empty($r['require_geolocation']));
@endphp
<form method="post" action="{{ route('envelopes.step.save', [$envelope, 2]) }}" id="wz-form2" data-suggest-url="{{ route('contacts.suggest') }}">
    @csrf @method('PUT')
    <div class="wz-hrow">
        <div class="wz-head"><h1 class="wz-h1">Quem irá assinar?</h1></div>
        <label class="wz-sw"><input type="checkbox" id="wz-adv" @checked($adv)><span class="wz-tr"></span><span>Autenticação avançada</span></label>
    </div>

    <div class="wz-opts">
        @if ($senderPolicy === 'never')
            <input type="hidden" name="sender_signs" value="0">
        @elseif ($senderPolicy === 'always')
            <input type="hidden" name="sender_signs" value="1">
            <span class="wz-muted"><i class="bx bx-check-shield" aria-hidden="true"></i> <strong>Você assina</strong> este documento (regra da sua empresa).</span>
        @else
            <input type="hidden" name="sender_signs" value="0">
            <label class="wz-sw"><input type="checkbox" id="wz-me" name="sender_signs" value="1" @checked($senderOn)><span class="wz-tr"></span><span>Eu vou assinar esse documento</span></label>
        @endif
        <button type="button" class="wz-link" id="wz-contacts"><i class="bx bx-user-circle" aria-hidden="true"></i> Contatos</button>
        <button type="button" class="wz-link" id="wz-obs-toggle" aria-controls="wz-obsbox" aria-expanded="{{ $obsRows ? 'true' : 'false' }}"><i class="bx bx-low-vision" aria-hidden="true"></i> Observadores</button>
    </div>
    @error('sender')<div class="wz-err" role="alert">{{ $message }} @if (str_contains($message, 'Meu perfil'))<a href="{{ route('profile.edit') }}#minha-assinatura">Abrir Meu perfil</a>@endif</div>@enderror
    @error('people')<div class="wz-err" role="alert">{{ $message }}</div>@enderror

    <div class="wz-note wz-adv-only">Sem e-mail aqui, ele só é pedido no último passo. A função "testemunha" assina depois dos signatários.</div>

    <div id="wz-people" data-next="{{ $next }}">
        @foreach ($rows as $i => $p)@include('documents.wizard._person', ['i' => $i, 'p' => $p])@endforeach
    </div>
    <template id="wz-person-tpl">@include('documents.wizard._person', ['i' => '__I__', 'p' => []])</template>

    <button type="button" class="wz-btn wz-pri" id="wz-add">+ Signatário(a)</button>

    <div class="wz-obsbox" id="wz-obsbox" @if (! $obsRows) hidden @endif>
        <h2 class="wz-h2">Observadores</h2>
        <p class="wz-muted">Observadores recebem uma cópia do documento assinado por e-mail quando todos assinarem. Eles não assinam e não recebem link.</p>
        <div id="wz-observers">
            @foreach ($obsRows as $i => $p)@include('documents.wizard._observer', ['i' => $i, 'p' => $p])@endforeach
        </div>
        <button type="button" class="wz-btn wz-sec" id="wz-add-obs">+ Observador(a)</button>
    </div>
    <template id="wz-observer-tpl">@include('documents.wizard._observer', ['i' => '__I__', 'p' => []])</template>

    <div class="wz-orderbox" id="wz-orderbox" hidden>
        <input type="hidden" name="signing_order" value="parallel">
        <label class="wz-sw"><input type="checkbox" name="signing_order" value="sequential" @checked($order === 'sequential')><span class="wz-tr"></span><span>Assinar em ordem (um de cada vez)</span></label>
    </div>

    <div class="wz-foot is-mid">
        <a class="wz-btn wz-sec" href="{{ route('envelopes.step', [$envelope, 1]) }}"><i class="bx bx-left-arrow-alt" aria-hidden="true"></i> VOLTAR</a>
        <button type="submit" class="wz-btn wz-pri">CONTINUAR <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></button>
    </div>
</form>

<div class="wz-modal-back" id="wz-contacts-modal" hidden>
    <div class="wz-modal" role="dialog" aria-modal="true" aria-labelledby="wz-ct-title">
        <h3 id="wz-ct-title">Contatos</h3>
        <div class="wz-field"><label for="wz-ct-q">Buscar por nome ou e-mail</label><input type="search" id="wz-ct-q" placeholder="Digite ao menos 2 letras" autocomplete="off"></div>
        <div class="wz-list" id="wz-ct-list" aria-live="polite"><p class="wz-muted wz-pad">Digite para buscar na sua agenda.</p></div>
        <div class="wz-mrow"><button type="button" class="wz-btn wz-sec" id="wz-ct-close">FECHAR</button></div>
    </div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('assets/js/wizard-people.js') }}?v=2"></script>
@endpush
