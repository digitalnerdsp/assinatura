@extends('layouts.app')
@section('title', $template->exists ? 'Editar modelo' : 'Novo modelo')
@section('section', 'Modelos')
@section('content')
@if (! $template->exists)
    {{-- Passo único: nome e PDF. As lacunas se marcam na tela de edição, logo depois. --}}
    <form method="post" action="{{ route('templates.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="row">
            <div class="col-12 col-xl-7">
                <div class="card radius-10">
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nome do modelo</label>
                            <input id="name" type="text" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" maxlength="150" placeholder="Ex.: Contrato de aluguel" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="pdf" class="form-label">Documento em PDF</label>
                            <input id="pdf" type="file" class="form-control @error('pdf') is-invalid @enderror" name="pdf" accept="application/pdf,.pdf" required>
                            <div class="form-text">Suba o documento com os espaços em branco onde os dados mudam (nome, valor, data). Depois você clica no documento para marcar cada lacuna.</div>
                            @error('pdf')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <button class="btn btn-primary"><i class="bx bx-upload"></i> Continuar</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@else
    <form method="post" action="{{ route('templates.update', $template) }}" id="tpl-form">
        @csrf @method('PUT')
        <input type="hidden" name="fields" id="tpl-fields-input" value="{{ old('fields') }}">
        <div class="card radius-10">
            <div class="card-body">
                @error('fields')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-6">
                        <label for="name" class="form-label">Nome do modelo</label>
                        <input id="name" type="text" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name', $template->name) }}" maxlength="150" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-lg-6 d-flex flex-wrap gap-2 justify-content-lg-end">
                        <button class="btn btn-primary" id="tpl-save"><i class="bx bx-save"></i> Salvar</button>
                        @if ($fields->isNotEmpty())<a class="btn btn-success" href="{{ route('templates.use', $template) }}"><i class="bx bx-file"></i> Usar este modelo</a>@endif
                    </div>
                </div>
            </div>
        </div>

        <div class="card radius-10">
            <div class="card-body">
                <h6 class="mb-1">Marque as lacunas</h6>
                <p class="text-secondary small mb-3">Clique no documento, no lugar onde o dado deve aparecer, e dê um nome à lacuna (por exemplo “Nome do contratante”). Arraste para mover e puxe o canto para ajustar o tamanho.</p>
                <div class="tpl" id="tpl-editor">
                    <div id="tpl-viewer" class="tpl-viewer" data-pdf-url="{{ route('templates.pdf', $template) }}" data-pages="{{ $template->page_count }}" data-fields="{{ json_encode($fields->map(fn ($f) => ['label' => $f->label, 'page' => $f->page, 'x' => $f->x, 'y' => $f->y, 'width' => $f->width, 'height' => $f->height, 'multiline' => $f->multiline, 'required' => $f->required])->values(), JSON_UNESCAPED_UNICODE) }}"></div>
                    <aside class="tpl-side" id="tpl-side" aria-label="Lacunas do modelo">
                        <h6 class="mb-2">Lacunas <span class="badge bg-secondary" id="tpl-count">{{ $fields->count() }}</span></h6>
                        <ol class="tpl-list" id="tpl-list">
                            @foreach ($fields as $f)<li>{{ $f->label }} <span class="text-secondary small">· página {{ $f->page }}</span></li>@endforeach
                        </ol>
                        <p class="text-secondary small mb-0" id="tpl-empty" @if ($fields->isNotEmpty()) hidden @endif>Nenhuma lacuna ainda.</p>
                    </aside>
                </div>
            </div>
        </div>
    </form>

    <div class="card radius-10">
        <div class="card-body">
            <h6>Trocar o PDF do modelo</h6>
            <form method="post" action="{{ route('templates.pdf.replace', $template) }}" enctype="multipart/form-data" class="row g-2 align-items-end" id="tpl-replace">
                @csrf
                <div class="col-12 col-md-6">
                    <input type="file" class="form-control @error('pdf') is-invalid @enderror" name="pdf" accept="application/pdf,.pdf" required aria-label="Novo PDF">
                    @error('pdf')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @error('confirm')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                @if ($fields->isNotEmpty())
                    <div class="col-12 col-md-4">
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="tpl-confirm"><label class="form-check-label" for="tpl-confirm">Sei que as lacunas serão apagadas</label></div>
                    </div>
                @endif
                <div class="col-12 col-md-2 d-grid"><button class="btn btn-outline-secondary">Trocar PDF</button></div>
            </form>
        </div>
    </div>

    <form method="post" action="{{ route('templates.destroy', $template) }}" onsubmit="return confirm('Remover este modelo? Os documentos já criados a partir dele não são afetados.')">
        @csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm"><i class="bx bx-trash"></i> Remover modelo</button>
    </form>
@endif
@endsection
