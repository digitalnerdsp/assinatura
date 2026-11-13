{{-- Menu ⋮ de um documento ($e): abrir, mover, etiquetas, lixeira/arquivo. --}}
<div class="dropdown">
    <button type="button" class="btn btn-sm btn-outline-secondary drive-tap border-0" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Ações do documento {{ $e->title }}"><i class="bx bx-dots-vertical-rounded"></i></button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item drive-tap justify-content-start" href="{{ route('envelopes.show', $e) }}"><i class="bx bx-file me-2"></i>Abrir</a></li>
        <li><button type="button" class="dropdown-item drive-tap justify-content-start" data-drive-action="move-doc" data-id="{{ $e->id }}" data-title="{{ $e->title }}" data-folder="{{ $e->folder_id }}"><i class="bx bx-move me-2"></i>Mover para…</button></li>
        <li><button type="button" class="dropdown-item drive-tap justify-content-start" data-drive-action="tags-doc" data-id="{{ $e->id }}" data-title="{{ $e->title }}" data-tags='@json($e->tags->pluck('id')->values())'><i class="bx bx-purchase-tag-alt me-2"></i>Etiquetas…</button></li>
        @if ($e->isTrashable())
            <li><form method="post" action="{{ route('envelopes.trash', $e) }}" onsubmit="return confirm('Mover este documento para a lixeira? Ele será apagado em 30 dias.')">@csrf<button class="dropdown-item drive-tap justify-content-start"><i class="bx bx-trash me-2"></i>Mover para a lixeira</button></form></li>
        @elseif ($e->isArchivable() && ! $e->isArchived())
            <li><form method="post" action="{{ route('envelopes.archive', $e) }}">@csrf<button class="dropdown-item drive-tap justify-content-start"><i class="bx bx-archive me-2"></i>Arquivar</button></form></li>
        @elseif ($e->isArchived())
            <li><form method="post" action="{{ route('envelopes.unarchive', $e) }}">@csrf<button class="dropdown-item drive-tap justify-content-start"><i class="bx bx-archive-out me-2"></i>Desarquivar</button></form></li>
        @endif
    </ul>
</div>
