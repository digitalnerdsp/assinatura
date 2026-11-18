@extends('documents.wizard.layout')
@section('title', 'Criar documento')
@section('wizard')
@php($saved = (int) $envelope->wizard_step >= 3)
<form method="post" action="{{ route('envelopes.step.save', [$envelope, 3]) }}" id="wz-form3"
      data-pdf="{{ route('envelopes.original', $envelope) }}"
      data-docs="{{ $placeDocs->toJson() }}"
      data-people="{{ $placePeople->toJson() }}"
      data-fields="{{ $placeFields->toJson() }}"
      data-types="{{ json_encode($fieldTypes) }}">
    @csrf @method('PUT')
    <input type="hidden" name="skipped" value="0" id="wz-skipped">
    <div id="wz-fields-inputs"></div>

    <div class="wz-head"><h1 class="wz-h1 wz-h1-sm">Posicione as assinaturas no documento <small class="wz-muted">(Opcional)</small></h1><a href="#" class="wz-help" id="wz-help"><i class="bx bxs-help-circle" aria-hidden="true"></i> Preciso de ajuda</a></div>
    <p class="wz-helptext" id="wz-helptext" hidden>Clique (ou toque) no ponto do documento onde a assinatura deve aparecer. Se não posicionar, as assinaturas ficam só no relatório de assinaturas, no fim do PDF.</p>
    @error('fields')<div class="wz-err" role="alert">{{ $message }}</div>@enderror
    @foreach ($errors->get('fields.*') as $msgs)@foreach ($msgs as $m)<div class="wz-err" role="alert">{{ $m }}</div>@endforeach @endforeach

    <div class="wz-bar">
        <a class="wz-btn wz-sec" href="{{ route('envelopes.step', [$envelope, 1]) }}"><i class="bx bx-left-arrow-alt" aria-hidden="true"></i> Editar documento</a>
        <button type="button" class="wz-btn wz-sec" id="wz-skip">Continuar sem posicionar</button>
        <button type="button" class="wz-btn wz-pri" id="wz-save" disabled>{{ $saved ? 'Salvar alterações' : 'Salvar e continuar' }} <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></button>
    </div>

    <div class="wz-howto">Como funciona:<br><strong>1. Clique ou toque</strong> no ponto do documento onde a assinatura deve ficar.<br><strong>2. Escolha o signatário</strong> e o tipo de campo; depois arraste ou redimensione.</div>

    <div class="wz-tabs" id="wz-tabs" role="tablist" aria-label="Arquivos do documento" @if ($placeDocs->count() < 2) hidden @endif></div>
    <div class="wz-viewer" id="wz-viewer" aria-label="Documento" aria-busy="true"><p class="wz-muted wz-pad" id="wz-loading">Carregando o documento…</p></div>
    <p class="wz-err" id="wz-loaderr" role="alert" hidden>Não foi possível mostrar o documento aqui. Você ainda pode continuar sem posicionar as assinaturas.</p>

    <div class="wz-foot wz-foot-left"><button type="button" class="wz-btn wz-pri" id="wz-save2" disabled>{{ $saved ? 'Salvar alterações' : 'Salvar e continuar' }} <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></button></div>
</form>

<div class="wz-modal-back" id="wz-skip-modal" hidden>
    <div class="wz-modal" role="dialog" aria-modal="true" aria-labelledby="wz-sk-title">
        <h3 id="wz-sk-title">Continuar sem posicionar assinaturas?</h3>
        <p>Reparamos que você ainda não posicionou assinaturas no corpo do documento, então elas ficarão apenas no relatório de assinaturas. Para posicionar, é só clicar sobre o local desejado no documento. Deseja continuar mesmo assim?</p>
        <div class="wz-mrow"><button type="button" class="wz-btn wz-sec" id="wz-sk-no">POSICIONAR AGORA</button><button type="button" class="wz-btn wz-pri" id="wz-sk-yes">CONTINUAR</button></div>
    </div>
</div>
@endsection
@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="{{ asset('assets/js/wizard-placement.js') }}?v=4"></script>
@endpush
