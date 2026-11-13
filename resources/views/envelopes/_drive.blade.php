{{-- Navegação de pastas e etiquetas por cima da lista de documentos (a lista em si não muda). --}}
@php
    $popper = '{"strategy":"fixed"}';
    $keep = array_filter(\Illuminate\Support\Arr::only($filters, ['q', 'status', 'arquivados']));
    $openFolder = fn ($id) => route('documents.folders', ['folder' => $id] + $keep);
@endphp
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <nav class="drive-breadcrumb flex-grow-1" aria-label="Pasta atual">
        <a href="{{ route('documents.folders', $keep) }}">Documentos</a>
        @foreach ($breadcrumb as $crumb)
            <span class="drive-sep" aria-hidden="true">›</span>
            <a href="{{ $openFolder($crumb->id) }}">{{ $crumb->name }}</a>
        @endforeach
    </nav>
    <div class="d-flex flex-wrap gap-2 ms-auto">
        <button type="button" class="btn btn-outline-primary drive-tap" data-drive-action="new-folder" data-parent="{{ $currentFolder?->id }}" @disabled($breadcrumb->count() >= 4) @if ($breadcrumb->count() >= 4) title="Limite de 4 níveis de pastas" @endif><i class="bx bx-folder-plus"></i> Nova pasta</button>
        <button type="button" class="btn btn-outline-primary drive-tap" data-drive-action="new-tag"><i class="bx bx-purchase-tag-alt"></i> Nova etiqueta</button>
        <div class="btn-group" role="group" aria-label="Visualização das pastas">
            <button type="button" class="btn btn-outline-secondary drive-tap" data-drive-view="grid" aria-label="Pastas em grade" aria-pressed="true"><i class="bx bx-grid-alt"></i></button>
            <button type="button" class="btn btn-outline-secondary drive-tap" data-drive-view="list" aria-label="Pastas em lista" aria-pressed="false"><i class="bx bx-list-ul"></i></button>
        </div>
    </div>
</div>

<div class="drive-folders drive-grid mb-3" id="drive-folders">
    @foreach ($childFolders as $f)
        @php($items = $folderCounts[$f->id] ?? 0)
        <div class="drive-folder card radius-10" data-folder-card="{{ $f->id }}" data-items="{{ $items }}">
            <div class="card-body d-flex align-items-center gap-2 py-2 pe-1">
                <i class="bx bx-folder fs-3 text-warning"></i>
                <div class="flex-grow-1 min-w-0">
                    <a class="fw-semibold d-block text-truncate" href="{{ $openFolder($f->id) }}">{{ $f->name }}</a>
                    <div class="small text-secondary">{{ $items }} {{ $items === 1 ? 'documento' : 'documentos' }}@if ($f->children_count) · {{ $f->children_count }} {{ $f->children_count === 1 ? 'subpasta' : 'subpastas' }}@endif</div>
                </div>
                <div class="dropdown">
                    <button type="button" class="btn btn-sm btn-outline-secondary drive-tap border-0" data-bs-toggle="dropdown" data-bs-popper-config='{{ $popper }}' aria-expanded="false" aria-label="Ações da pasta {{ $f->name }}"><i class="bx bx-dots-vertical-rounded"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item drive-tap justify-content-start" href="{{ $openFolder($f->id) }}"><i class="bx bx-folder-open me-2"></i>Abrir</a></li>
                        <li><button type="button" class="dropdown-item drive-tap justify-content-start" data-drive-action="rename-folder" data-id="{{ $f->id }}" data-name="{{ $f->name }}"><i class="bx bx-rename me-2"></i>Renomear</button></li>
                        <li><button type="button" class="dropdown-item drive-tap justify-content-start" data-drive-action="move-folder" data-id="{{ $f->id }}" data-name="{{ $f->name }}" data-parent="{{ $f->parent_id }}"><i class="bx bx-move me-2"></i>Mover para…</button></li>
                        <li><button type="button" class="dropdown-item drive-tap justify-content-start text-danger" data-drive-action="delete-folder" data-id="{{ $f->id }}" data-name="{{ $f->name }}"><i class="bx bx-trash me-2"></i>Apagar</button></li>
                    </ul>
                </div>
            </div>
        </div>
    @endforeach
</div>

@if ($tags->isNotEmpty())
    <div class="drive-tags d-flex flex-wrap gap-2 mb-3" id="drive-tags">
        @foreach ($tags as $t)
            <div class="drive-chip btn-group" data-tag-chip="{{ $t->id }}">
                <a href="{{ route('documents.folders', array_filter(['folder' => $currentFolder?->id, 'tag' => $t->id] + $keep)) }}" class="badge bg-{{ $t->color }} fs-6 fw-semibold drive-tap rounded-start rounded-end-0">{{ $t->name }} <span class="ms-1">{{ $t->envelopes_count }}</span></a>
                <div class="dropdown">
                    <button type="button" class="badge bg-{{ $t->color }} fs-6 border-0 drive-tap rounded-start-0 rounded-end" data-bs-toggle="dropdown" data-bs-popper-config='{{ $popper }}' aria-expanded="false" aria-label="Ações da etiqueta {{ $t->name }}"><i class="bx bx-chevron-down"></i></button>
                    <ul class="dropdown-menu">
                        <li><button type="button" class="dropdown-item drive-tap justify-content-start" data-drive-action="edit-tag" data-id="{{ $t->id }}" data-name="{{ $t->name }}" data-color="{{ $t->color }}"><i class="bx bx-edit me-2"></i>Renomear ou mudar a cor</button></li>
                        <li>
                            <form method="post" action="{{ route('tags.destroy', $t) }}" onsubmit="return confirm('Apagar a etiqueta {{ addslashes($t->name) }}? Os documentos continuam, só perdem a etiqueta.')">@csrf @method('DELETE')
                                <button class="dropdown-item drive-tap justify-content-start text-danger" data-drive-action="delete-tag"><i class="bx bx-trash me-2"></i>Apagar</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        @endforeach
    </div>
@endif
