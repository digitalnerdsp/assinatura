@extends('documents.wizard.layout')
@section('title', 'Criar documento')
@section('wizard')
@php
    $folderId = old('folder_id', $envelope?->folder_id);
    $currentName = $folderId && isset($folderPaths[$folderId]) ? $folderPaths[$folderId] : 'Documentos';
@endphp
<form method="post" action="{{ $envelope ? route('envelopes.step.save', [$envelope, 1]) : route('envelopes.store') }}" enctype="multipart/form-data" id="wz-form" data-quick-folder="{{ route('documents.folders.quick') }}" data-has-file="{{ $envelope ? '1' : '0' }}" data-max="{{ $maxDocuments }}" data-room="{{ $envelope ? max(0, $maxDocuments - $files->count()) : $maxDocuments }}">
    @csrf
    @if ($envelope)@method('PUT')@endif
    <input type="hidden" name="folder_id" id="wz-folder-id" value="{{ $folderId }}">

    <div class="wz-head"><h1 class="wz-h1">Novo documento</h1><a href="#" class="wz-help" id="wz-help"><i class="bx bxs-help-circle" aria-hidden="true"></i> Preciso de ajuda</a></div>
    <p class="wz-helptext" id="wz-helptext" hidden>Escolha os arquivos (PDF ou DOCX) que serão assinados; com vários, quem assina assina uma vez só. Arquivos do Word são convertidos em PDF: confira o resultado ao posicionar as assinaturas. Na etapa seguinte você diz quem assina; depois, onde cada assinatura aparece no documento.</p>

    <div class="wz-folder"><i class="bx bxs-folder" aria-hidden="true"></i> <strong id="wz-folder-name">{{ $currentName }}</strong> <button type="button" class="wz-link" id="wz-change-folder">Alterar pasta</button></div>
    @error('folder_id')<div class="wz-err" role="alert">{{ $message }}</div>@enderror

    <h2 class="wz-h2">Quais documentos serão assinados?</h2>
    @error('document')<div class="wz-err" role="alert">{{ $message }}</div>@enderror

    <input type="file" name="{{ $envelope ? 'document' : 'documents[]' }}" id="wz-file" @if (! $envelope) multiple @endif accept="application/pdf,.pdf,.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="wz-sr">
    <div class="wz-drop" id="wz-drop" @if ($envelope) hidden @endif>
        <div>
            <span class="wz-up" aria-hidden="true"><i class="bx bx-up-arrow-alt"></i></span>
            <div>Arraste e solte ou <button type="button" class="wz-link" id="wz-pick">busque pelos arquivos</button></div>
            <div class="wz-small"><strong>PDF ou DOCX</strong>, até {{ config('signing.max_pdf_mb') }} MB cada. Você pode escolher vários: todos são assinados de uma vez.</div>
        </div>
    </div>
    <div id="wz-chosen" @if (! $envelope) hidden @endif>
        <button type="button" class="wz-again" id="wz-pick2"><i class="bx bx-upload" aria-hidden="true"></i> {{ $envelope ? 'Trocar o primeiro arquivo' : 'Adicionar mais arquivos' }}</button>
        @if ($envelope)
            @foreach ($files as $f)
                <div class="wz-frow">
                    <strong>{{ $f->n }}</strong><span class="wz-fname">{{ $f->name }}</span>
                    <a class="wz-ib" href="{{ route('envelopes.original', ['envelope' => $envelope, 'n' => $f->n]) }}" target="_blank" rel="noopener" aria-label="Abrir {{ $f->name }} em outra aba"><i class="bx bx-link-external" aria-hidden="true"></i></a>
                    @if ($f->extraId)<label class="wz-small wz-rmfile"><input type="checkbox" name="remove[]" value="{{ $f->extraId }}"> Remover</label>@endif
                </div>
            @endforeach
            <p class="wz-muted wz-small">Se trocar o primeiro arquivo, as assinaturas já posicionadas serão apagadas.</p>
            @if ($files->count() < $maxDocuments)
                <input type="file" name="documents[]" id="wz-add" multiple accept="application/pdf,.pdf,.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="wz-sr">
                <button type="button" class="wz-again" id="wz-add-btn"><i class="bx bx-plus" aria-hidden="true"></i> Adicionar mais arquivos</button>
                <div id="wz-add-names" aria-live="polite"></div>
            @endif
        @else
            <div id="wz-list" aria-live="polite"></div>
            <p class="wz-muted wz-small" id="wz-count"></p>
        @endif
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
