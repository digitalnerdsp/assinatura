{{-- Popups de pastas e etiquetas. Em tela cheia abaixo de 576 px (celular). O comportamento está em assets/js/drive.js. --}}

<div class="modal fade" id="modal-folder" tabindex="-1" aria-labelledby="modal-folder-title" aria-hidden="true"
     data-url-store="{{ route('folders.store') }}" data-url-update="{{ route('folders.update', '__ID__') }}">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form class="modal-content" method="post">
            @csrf
            <input type="hidden" name="_method" value="PUT" disabled>
            <input type="hidden" name="parent_id" value="">
            <div class="modal-header"><h5 class="modal-title" id="modal-folder-title">Nova pasta</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <label class="form-label" for="folder-name">Nome da pasta</label>
                <input type="text" class="form-control form-control-lg" id="folder-name" name="name" maxlength="120" required autocomplete="off">
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary drive-tap" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary drive-tap">Salvar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modal-tag" tabindex="-1" aria-labelledby="modal-tag-title" aria-hidden="true"
     data-url-store="{{ route('tags.store') }}" data-url-update="{{ route('tags.update', '__ID__') }}">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form class="modal-content" method="post">
            @csrf
            <input type="hidden" name="_method" value="PUT" disabled>
            <div class="modal-header"><h5 class="modal-title" id="modal-tag-title">Nova etiqueta</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <label class="form-label" for="tag-name">Nome da etiqueta</label>
                <input type="text" class="form-control form-control-lg mb-3" id="tag-name" name="name" maxlength="60" required autocomplete="off">
                <div class="form-label">Cor</div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($colors as $c)
                        <label class="drive-color drive-tap"><input type="radio" name="color" value="{{ $c }}" class="visually-hidden" @checked($loop->first)><span class="badge bg-{{ $c }} fs-6">{{ $c }}</span></label>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary drive-tap" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary drive-tap">Salvar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modal-move" tabindex="-1" aria-labelledby="modal-move-title" aria-hidden="true"
     data-tree="{{ json_encode($folderTree, JSON_UNESCAPED_UNICODE) }}"
     data-url-folder="{{ route('folders.move', '__ID__') }}" data-url-doc="{{ route('envelopes.move', '__ID__') }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content" method="post">
            @csrf
            <div class="modal-header"><h5 class="modal-title" id="modal-move-title">Mover para…</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body"><p class="small text-secondary mb-2" id="move-subject"></p><div id="move-tree" role="radiogroup" aria-label="Pasta de destino"></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary drive-tap" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary drive-tap">Mover aqui</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modal-delete-folder" tabindex="-1" aria-labelledby="modal-delete-title" aria-hidden="true"
     data-url-impact="{{ route('folders.impact', '__ID__') }}" data-url-destroy="{{ route('folders.destroy', '__ID__') }}">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form class="modal-content" method="post">
            @csrf @method('DELETE')
            <div class="modal-header"><h5 class="modal-title" id="modal-delete-title">Apagar pasta</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <p class="fw-semibold mb-2" id="delete-subject"></p>
                <p id="delete-impact" class="mb-0" aria-live="polite">Calculando…</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary drive-tap" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger drive-tap" id="delete-confirm" disabled>Apagar pasta</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modal-doc-tags" tabindex="-1" aria-labelledby="modal-doc-tags-title" aria-hidden="true"
     data-url="{{ route('envelopes.retag', '__ID__') }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content" method="post">
            @csrf
            <div class="modal-header"><h5 class="modal-title" id="modal-doc-tags-title">Etiquetas do documento</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <p class="small text-secondary mb-2" id="doc-tags-subject"></p>
                @forelse ($tags as $t)
                    <label class="d-flex align-items-center gap-2 drive-tap justify-content-start"><input class="form-check-input mt-0" type="checkbox" name="tags[]" value="{{ $t->id }}"><span class="badge bg-{{ $t->color }} fs-6">{{ $t->name }}</span></label>
                @empty
                    <p class="text-secondary mb-0">Nenhuma etiqueta ainda. Use <strong>Nova etiqueta</strong> para criar a primeira.</p>
                @endforelse
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary drive-tap" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary drive-tap">Salvar</button></div>
        </form>
    </div>
</div>
