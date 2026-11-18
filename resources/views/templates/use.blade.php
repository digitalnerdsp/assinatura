@extends('layouts.app')
@section('title', 'Usar: '.$template->name)
@section('section', 'Modelos')
@section('content')
@if ($fields->isEmpty())
    <div class="card radius-10"><div class="card-body">
        <p class="mb-2">Marque ao menos uma lacuna no modelo antes de usá-lo.</p>
        <a class="btn btn-primary" href="{{ route('templates.edit', $template) }}"><i class="bx bx-edit"></i> Marcar lacunas</a>
    </div></div>
@else
<form method="post" action="{{ route('templates.generate', $template) }}" id="tpl-use">
    @csrf
    {{-- Primeiro botão do formulário: o Enter nos campos aciona "Continuar", não a prévia --}}
    <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true">Continuar</button>
    <div class="row">
        <div class="col-12 col-xl-7">
            <div class="card radius-10">
                <div class="card-header"><h6 class="mb-0"><i class="bx bx-file me-1"></i> Preencha os dados do documento</h6></div>
                <div class="card-body">
                    @error('values')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
                    <div class="mb-3">
                        <label for="title" class="form-label">Título do documento</label>
                        <input id="title" type="text" class="form-control @error('title') is-invalid @enderror" name="title" value="{{ old('title', $template->name) }}" maxlength="190" required>
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @if ($folderPaths)
                        <div class="mb-3">
                            <label for="folder_id" class="form-label">Pasta <span class="text-secondary">(opcional)</span></label>
                            <select id="folder_id" class="form-select" name="folder_id"><option value="">Sem pasta</option>@foreach ($folderPaths as $id => $path)<option value="{{ $id }}" @selected(old('folder_id') === $id)>{{ $path }}</option>@endforeach</select>
                        </div>
                    @endif
                    <hr>
                    @foreach ($fields as $f)
                        <div class="mb-3">
                            <label class="form-label" for="v_{{ $f->key }}">{{ $f->label }} @unless ($f->required)<span class="text-secondary fw-normal">(opcional)</span>@endunless</label>
                            @if ($f->multiline)
                                <textarea id="v_{{ $f->key }}" class="form-control @error('values.'.$f->key) is-invalid @enderror" name="values[{{ $f->key }}]" rows="3" maxlength="500" @required($f->required)>{{ old('values.'.$f->key) }}</textarea>
                            @else
                                <input id="v_{{ $f->key }}" type="text" class="form-control @error('values.'.$f->key) is-invalid @enderror" name="values[{{ $f->key }}]" value="{{ old('values.'.$f->key) }}" maxlength="500" @required($f->required)>
                            @endif
                            @error('values.'.$f->key)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="card radius-10">
                <div class="card-body">
                    <p class="text-secondary">Confira como o PDF fica antes de seguir. Depois você escolhe quem assina, posiciona as assinaturas e envia.</p>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-outline-secondary" formaction="{{ route('templates.preview', $template) }}" formtarget="_blank"><i class="bx bx-show"></i> Ver prévia</button>
                        <button class="btn btn-primary btn-lg"><i class="bx bx-right-arrow-alt"></i> Continuar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endif
@endsection

@push('scripts')
<script>
// Evita enviar duas vezes ao clicar rápido em "Continuar" (a prévia abre em outra aba e não conta).
(function () {
    var f = document.getElementById('tpl-use'); if (!f) { return; }
    window.addEventListener('pageshow', function () { delete f.dataset.sent; });
    f.addEventListener('submit', function (e) {
        var s = e.submitter; if (s && s.getAttribute('formtarget') === '_blank') { return; }
        if (f.dataset.sent) { e.preventDefault(); return; } f.dataset.sent = '1';
    });
})();
</script>
@endpush
