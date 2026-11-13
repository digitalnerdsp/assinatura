{{-- Navegação de pastas e etiquetas por cima da lista de documentos (a lista em si não muda). --}}
<nav class="drive-breadcrumb mb-3" aria-label="Pasta atual">
    <a href="{{ route('envelopes.index') }}">Documentos</a>
    @foreach ($breadcrumb as $crumb)
        <span class="drive-sep" aria-hidden="true">›</span>
        <a href="{{ route('envelopes.index', ['folder' => $crumb->id]) }}">{{ $crumb->name }}</a>
    @endforeach
</nav>

<div class="drive-folders row g-2 mb-3" id="drive-folders">
    @foreach ($childFolders as $f)
        @php($items = $folderCounts[$f->id] ?? 0)
        <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
            <div class="drive-folder card radius-10 h-100" data-folder-card="{{ $f->id }}" data-items="{{ $items }}">
                <div class="card-body d-flex align-items-center gap-2 py-2">
                    <i class="bx bx-folder fs-3 text-warning"></i>
                    <div class="flex-grow-1 text-truncate">
                        <a class="fw-semibold" href="{{ route('envelopes.index', ['folder' => $f->id]) }}">{{ $f->name }}</a>
                        <div class="small text-secondary">{{ $items }} {{ $items === 1 ? 'documento' : 'documentos' }}@if ($f->children_count) · {{ $f->children_count }} {{ $f->children_count === 1 ? 'subpasta' : 'subpastas' }}@endif</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

@if ($tags->isNotEmpty())
    <div class="drive-tags d-flex flex-wrap gap-2 mb-3" id="drive-tags">
        @foreach ($tags as $t)
            <a href="{{ route('envelopes.index', ['tag' => $t->id]) }}" class="badge bg-{{ $t->color }} fs-6 fw-normal" data-tag-chip="{{ $t->id }}">{{ $t->name }} <span class="opacity-75">{{ $t->envelopes_count }}</span></a>
        @endforeach
    </div>
@endif
