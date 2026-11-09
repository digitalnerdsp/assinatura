@extends('layouts.app')
@section('title', $template->exists ? 'Editar modelo' : 'Novo modelo')
@section('section', 'Modelos')
@section('content')
<form method="post" action="{{ $template->exists ? route('templates.update', $template) : route('templates.store') }}">
    @csrf
    @if ($template->exists) @method('PUT') @endif
    <div class="row">
        <div class="col-12 col-xl-8">
            <div class="card radius-10">
                <div class="card-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nome do modelo</label>
                        <input id="name" type="text" class="form-control" name="name" value="{{ old('name', $template->name) }}" required>
                    </div>
                    <div class="mb-3">
                        <label for="body" class="form-label">Texto do documento (HTML simples)</label>
                        <textarea id="body" class="form-control mono" name="body" rows="18" required>{{ old('body', $template->body) }}</textarea>
                        <div class="form-text">Use <code>@{{variavel}}</code> onde o valor muda a cada documento (letras, números e _). Tags aceitas: p, br, strong, em, u, h1-h4, ul/ol/li, table, hr, div, span, blockquote. Estilos e imagens externas são removidos.</div>
                    </div>
                    <button class="btn btn-primary"><i class="bx bx-save"></i> Salvar</button>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            @if ($template->exists)
                <div class="card radius-10">
                    <div class="card-body">
                        <h6>Variáveis encontradas</h6>
                        @forelse ($template->variables() as $v)<span class="badge bg-light text-dark border me-1">{{ $v }}</span>@empty<span class="text-secondary">Nenhuma.</span>@endforelse
                        <div class="d-grid gap-2 mt-3">
                            <a class="btn btn-outline-secondary" target="_blank" href="{{ route('templates.preview', $template) }}"><i class="bx bx-show"></i> Pré-visualizar PDF</a>
                            <a class="btn btn-success" href="{{ route('templates.use', $template) }}"><i class="bx bx-file"></i> Gerar documento</a>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</form>
@if ($template->exists)
    <form method="post" action="{{ route('templates.destroy', $template) }}" onsubmit="return confirm('Remover este modelo? Os envelopes já gerados não são afetados.')">
        @csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm"><i class="bx bx-trash"></i> Remover modelo</button>
    </form>
@endif
@endsection
