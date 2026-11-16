@extends('documents.wizard.layout')
@section('title', 'Criar documento')
@section('wizard')
@php
    $folderId = old('folder_id', $envelope?->folder_id);
    $currentName = $folderId && isset($folderPaths[$folderId]) ? $folderPaths[$folderId] : 'Documentos';
@endphp
<form method="post" action="{{ $envelope ? route('envelopes.step.save', [$envelope, 1]) : route('envelopes.store') }}" enctype="multipart/form-data" id="wz-form" data-quick-folder="{{ route('documents.folders.quick') }}" data-has-file="{{ $envelope ? '1' : '0' }}">
    @csrf
    @if ($envelope)@method('PUT')@endif
    <input type="hidden" name="folder_id" id="wz-folder-id" value="{{ $folderId }}">

    <div class="wz-head"><h1 class="wz-h1">Novo documento</h1><a href="#" class="wz-help" id="wz-help"><i class="bx bxs-help-circle" aria-hidden="true"></i> Preciso de ajuda</a></div>
    <p class="wz-helptext" id="wz-helptext" hidden>Escolha o PDF que será assinado. Na etapa seguinte você diz quem assina; depois, onde cada assinatura aparece no documento.</p>

    <div class="wz-folder"><i class="bx bxs-folder" aria-hidden="true"></i> <strong id="wz-folder-name">{{ $currentName }}</strong> <button type="button" class="wz-link" id="wz-change-folder">Alterar pasta</button></div>
    @error('folder_id')<div class="wz-err" role="alert">{{ $message }}</div>@enderror

    <h2 class="wz-h2">Qual documento será assinado?</h2>
    @error('document')<div class="wz-err" role="alert">{{ $message }}</div>@enderror

    <input type="file" name="document" id="wz-file" accept="application/pdf,.pdf" class="wz-sr">
    <div class="wz-drop" id="wz-drop" @if ($envelope) hidden @endif>
        <div>
            <span class="wz-up" aria-hidden="true"><i class="bx bx-up-arrow-alt"></i></span>
            <div>Arraste e solte ou <button type="button" class="wz-link" id="wz-pick">busque pelo arquivo</button></div>
            <div class="wz-small"><strong>PDF</strong> até {{ config('signing.max_pdf_mb') }} MB</div>
        </div>
    </div>
    <div id="wz-chosen" @if (! $envelope) hidden @endif>
        <button type="button" class="wz-again" id="wz-pick2"><i class="bx bx-upload" aria-hidden="true"></i> Subir outro arquivo</button>
        <div class="wz-frow">
            <strong>1</strong><span class="wz-fname" id="wz-fname">{{ $envelope?->document->name }}</span>
            @if ($envelope)<a class="wz-ib" href="{{ route('envelopes.original', $envelope) }}" target="_blank" rel="noopener" aria-label="Abrir o PDF em outra aba"><i class="bx bx-link-external" aria-hidden="true"></i></a>@endif
            <button type="button" class="wz-ib" id="wz-remove" aria-label="Escolher outro arquivo" @if ($envelope) hidden @endif><i class="bx bx-x" aria-hidden="true"></i></button>
        </div>
        @if ($envelope)<p class="wz-muted wz-small">Se subir outro arquivo, as assinaturas já posicionadas serão apagadas.</p>@endif
        <div class="wz-field">
            <label for="wz-title">Nome do documento</label>
            <input type="text" id="wz-title" name="title" maxlength="190" value="{{ old('title', $envelope?->title) }}">
        </div>
    </div>

    <div class="wz-foot"><button type="submit" class="wz-btn wz-pri" id="wz-next" disabled>CONTINUAR <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></button></div>
</form>

<div class="wz-modal-back" id="wz-folder-modal" hidden>
    <div class="wz-modal" role="dialog" aria-modal="true" aria-labelledby="wz-fm-title">
        <h3 id="wz-fm-title">Pasta de destino</h3>
        <div class="wz-list" id="wz-folder-list" role="radiogroup" aria-label="Pastas">
            <button type="button" class="wz-opt" data-id="" role="radio">Documentos (sem pasta)</button>
            @foreach ($folderPaths as $id => $path)<button type="button" class="wz-opt" data-id="{{ $id }}" role="radio">{{ $path }}</button>@endforeach
        </div>
        <div class="wz-field"><label for="wz-new-folder">Criar pasta nova</label><input type="text" id="wz-new-folder" maxlength="200" placeholder="Nome da pasta"></div>
        <div class="wz-err" id="wz-fm-err" role="alert" hidden></div>
        <div class="wz-mrow"><button type="button" class="wz-btn wz-sec" id="wz-fm-cancel">CANCELAR</button><button type="button" class="wz-btn wz-pri" id="wz-fm-ok">SALVAR</button></div>
    </div>
</div>
@endsection
