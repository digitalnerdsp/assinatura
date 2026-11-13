{{-- Documentos que estão diretamente na pasta aberta, como arquivos no Explorador. --}}
@foreach ($documents as $e)
    <div class="drive-folder drive-doc card radius-10" data-doc-item="{{ $e->id }}">
        <div class="card-body d-flex align-items-center gap-2 py-2 pe-1">
            <i class="bx bxs-file-pdf fs-3 text-danger" aria-hidden="true"></i>
            <div class="flex-grow-1 min-w-0">
                <a class="fw-semibold d-block text-truncate" href="{{ route('envelopes.show', $e) }}">{{ $e->title }}</a>
                <div class="d-flex flex-wrap align-items-center gap-1 small">
                    @include('partials.status-badge', ['status' => $e->status->value])
                    @if ($e->isArchived())<span class="badge bg-secondary">Arquivado</span>@endif
                    @foreach ($e->tags as $t)<span class="badge bg-{{ $t->color }}">{{ $t->name }}</span>@endforeach
                </div>
            </div>
            @include('envelopes._doc_menu', ['e' => $e])
        </div>
    </div>
@endforeach