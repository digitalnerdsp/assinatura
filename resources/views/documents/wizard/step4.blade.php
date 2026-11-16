@extends('documents.wizard.layout')
@section('title', 'Criar documento')
@section('wizard')
@php
    $auto = (bool) old('auto', $sendAuto);
    $channels = old('channels', $sendChannels);
    $remind = (bool) old('remind', $remindOn);
    $days = (int) old('remind_days', $remindDays);
    $limit = (bool) old('limit', $limitOn);
    $date = old('limit_date', $limitDate);
    $refuse = (bool) old('allow_refuse', $envelope->allow_refuse);
    $tagsOn = old('tags', $selectedTags);
@endphp
<form method="post" action="{{ route('envelopes.finish', $envelope) }}" id="wz-form4" data-save="{{ route('envelopes.step.save', [$envelope, 4]) }}">
    @csrf
    <div class="wz-head"><h1 class="wz-h1 wz-h1-sm">Envie o documento</h1><a href="#" class="wz-help" id="wz-help"><i class="bx bxs-help-circle" aria-hidden="true"></i> Preciso de ajuda</a></div>
    <p class="wz-helptext" id="wz-helptext" hidden>Com o envio automático, cada signatário recebe o link no e-mail. Sem ele, o documento é liberado e você copia o link de cada signatário no próximo passo para enviar por onde preferir.</p>
    @error('options')<div class="wz-err" role="alert">{{ $message }}</div>@enderror

    <h2 class="wz-h2">Como enviar o documento para assinatura?</h2>
    <p class="wz-info">Com o envio automático ligado, cada signatário recebe o link de assinatura por e-mail. Desligado, os links aparecem no documento assim que ele for liberado, para você copiar e enviar por qualquer canal (inclusive WhatsApp).</p>

    <input type="hidden" name="auto" value="0">
    <label class="wz-sw"><input type="checkbox" name="auto" value="1" id="wz-auto" @checked($auto)><span class="wz-tr"></span><span>Ativar envio automático dos links</span></label>

    <div id="wz-auto-box">
        <fieldset class="wz-chans"><legend class="wz-muted wz-small">Enviar o convite por</legend>
            @foreach (['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'] as $key => $label)
                <label class="wz-chk"><input type="checkbox" name="channels[]" value="{{ $key }}" @checked(in_array($key, $channels))> {{ $label }}</label>
            @endforeach
            <span class="wz-muted wz-small">WhatsApp e SMS usam o telefone do signatário e dependem do provedor configurado pelo administrador da plataforma.</span>
        </fieldset>
    </div>

    @forelse ($people as $s)
        <div class="wz-srow">
            <strong class="wz-sname">{{ $s->name }} @if ($s->role === 'witness')<small class="wz-muted">(testemunha)</small>@endif</strong>
            <div class="wz-field wz-semail">
                <label for="wz-em-{{ $s->id }}">E-mail do signatário <span class="wz-req" id="wz-req-{{ $s->id }}">{{ $auto ? '*' : '(opcional)' }}</span></label>
                <input type="email" id="wz-em-{{ $s->id }}" name="emails[{{ $s->id }}]" value="{{ old('emails.'.$s->id, $s->email) }}" maxlength="190" autocomplete="off">
                @error('emails.'.$s->id)<div class="wz-err" role="alert">{{ $message }}</div>@enderror
            </div>
        </div>
    @empty
        <p class="wz-muted">Só você assina este documento.</p>
    @endforelse

    <div class="wz-field wz-wide">
        <label for="wz-msg">(Opcional) Mensagem personalizada</label>
        <textarea id="wz-msg" name="message" rows="3" maxlength="500">{{ old('message', $envelope->message) }}</textarea>
        <div class="wz-count"><span>Mensagem será enviada no corpo do e-mail.</span><span id="wz-cnt">{{ mb_strlen((string) old('message', $envelope->message)) }} / 500</span></div>
        @error('message')<div class="wz-err" role="alert">{{ $message }}</div>@enderror
    </div>

    <input type="hidden" name="remind" value="0">
    <div class="wz-chk wz-inline" id="wz-remind-row">
        <input type="checkbox" name="remind" value="1" id="wz-remind" @checked($remind)>
        <label for="wz-remind">Ativar lembretes automáticos a cada</label>
        <select name="remind_days" id="wz-remind-days" aria-label="Dias entre os lembretes">@foreach ([1, 2, 3, 5, 7, 10, 14] as $d)<option value="{{ $d }}" @selected($days === $d)>{{ $d }}</option>@endforeach</select> dias.
    </div>

    <input type="hidden" name="limit" value="0">
    <div class="wz-chk wz-inline">
        <input type="checkbox" name="limit" value="1" id="wz-limit" @checked($limit)>
        <label for="wz-limit">Data limite para assinatura do documento</label>
        <input type="date" name="limit_date" id="wz-limit-date" value="{{ $date }}" min="{{ now()->addDay()->toDateString() }}" max="{{ now()->addDays(365)->toDateString() }}" aria-label="Data limite" @if (! $limit) hidden @endif>
    </div>
    @error('limit_date')<div class="wz-err" role="alert">{{ $message }}</div>@enderror

    <input type="hidden" name="allow_refuse" value="0">
    <div class="wz-chk"><input type="checkbox" name="allow_refuse" value="1" id="wz-refuse" @checked($refuse)><label for="wz-refuse">Permitir recusar o documento</label></div>

    @if ($allTags->isNotEmpty())
        <div class="wz-tags"><span class="wz-muted wz-small">Etiquetas</span>
            @foreach ($allTags as $t)<label class="wz-chk wz-tag"><input type="checkbox" name="tags[]" value="{{ $t->id }}" @checked(in_array($t->id, (array) $tagsOn))> <span class="badge bg-{{ $t->color }}">{{ $t->name }}</span></label>@endforeach
        </div>
    @endif

    <div class="wz-foot">
        <button type="submit" class="wz-btn wz-sec" formaction="{{ route('envelopes.step.save', [$envelope, 4]) }}" name="_method" value="PUT" formnovalidate><i class="bx bx-left-arrow-alt" aria-hidden="true"></i> VOLTAR</button>
        <button type="submit" class="wz-btn wz-pri" id="wz-send">ENVIAR E FINALIZAR</button>
    </div>
    <input type="hidden" name="go" value="" id="wz-go">
</form>
@endsection
@push('scripts')
<script src="{{ asset('assets/js/wizard-send.js') }}?v=1"></script>
@endpush
