{{-- Lista de documentos (filtros, busca, tabela e paginação). Usada por Documentos criados e por Pastas e etiquetas. --}}
@php
    $activeFilters = array_filter(\Illuminate\Support\Arr::except($filters, $showFolderSelect ? [] : ['folder']));
    $keepFolder = (! $showFolderSelect && ! empty($filters['folder'])) ? ['folder' => $filters['folder']] : [];
@endphp
<div class="card radius-10">
    <div class="card-body">
        <form method="get" action="{{ route($listRoute) }}" class="row g-2 mb-3">
            @if ($onlyArchived)<input type="hidden" name="arquivados" value="1">@endif
            @if (! $showFolderSelect && ! empty($filters['folder']))<input type="hidden" name="folder" value="{{ $filters['folder'] }}">@endif
            <div class="col-12 col-md-4"><input type="search" class="form-control" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Buscar por título, signatário, e-mail ou código"></div>
            <div class="col-6 col-md-2">
                <select class="form-select" name="status">
                    <option value="">Todos os status</option>
                    @foreach (\App\Enums\EnvelopeStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ __('status.'.$s->value) }}</option>@endforeach
                </select>
            </div>
            @if ($showFolderSelect)
            <div class="col-6 col-md-2">
                <select class="form-select" name="folder">
                    <option value="">Todas as pastas</option>
                    <option value="none" @selected(($filters['folder'] ?? '') === 'none')>Sem pasta</option>
                    @foreach ($folderPaths as $folderId => $folderPath)<option value="{{ $folderId }}" @selected(($filters['folder'] ?? '') === $folderId)>{{ $folderPath }}</option>@endforeach
                </select>
            </div>
            @endif
            <div class="col-6 col-md-2">
                <select class="form-select" name="tag">
                    <option value="">Todas as etiquetas</option>
                    @foreach ($tags as $t)<option value="{{ $t->id }}" @selected(($filters['tag'] ?? '') === $t->id)>{{ $t->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-auto d-flex gap-2">
                <button class="btn btn-outline-primary"><i class="bx bx-search"></i> Filtrar</button>
                @if ($activeFilters)<a class="btn btn-outline-secondary" href="{{ route($listRoute, $keepFolder) }}">Limpar</a>@endif
            </div>
        </form>

        <div class="mb-3 small">
            @if ($onlyArchived)<strong>Arquivados</strong> · <a href="{{ route($listRoute, $keepFolder) }}">Ver ativos</a>@else<a href="{{ route($listRoute, $keepFolder + ['arquivados' => 1]) }}"><i class="bx bx-archive"></i> Ver arquivados</a>@endif
        </div>

        @if ($envelopes->isEmpty())
            <div class="text-center py-5">
                <i class="bx bx-file fs-1 text-secondary"></i>
                <p class="text-secondary mt-2">{{ $activeFilters ? 'Nenhum documento encontrado com esses filtros.' : 'Nenhum documento ainda. Crie o primeiro para enviar um PDF para assinatura.' }}</p>
                @unless ($activeFilters)<a class="btn btn-primary" href="{{ route('envelopes.create') }}">Criar documento</a>@endunless
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0 drive-docs">
                    <thead class="table-light"><tr><th>Título</th><th>Pasta / etiquetas</th><th>Status</th><th>Signatários</th><th>Criado em</th><th class="text-end" aria-label="Ações"></th></tr></thead>
                    <tbody>
                    @foreach ($envelopes as $e)
                        <tr>
                            <td data-label="Título"><a href="{{ route('envelopes.show', $e) }}" class="fw-semibold">{{ $e->title }}</a></td>
                            <td data-label="Pasta / etiquetas">
                                @if ($e->folder_id && isset($folderPaths[$e->folder_id]))<span class="text-secondary small me-1"><i class="bx bx-folder"></i> {{ $folderPaths[$e->folder_id] }}</span>@endif
                                @foreach ($e->tags as $t)<span class="badge bg-{{ $t->color }} me-1">{{ $t->name }}</span>@endforeach
                            </td>
                            <td data-label="Status">@include('partials.status-badge', ['status' => $e->status->value])</td>
                            <td data-label="Signatários">{{ $e->signers_count }}</td>
                            <td data-label="Criado em">{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                            <td data-label="Ações" class="text-end">
                                @include('envelopes._doc_menu', ['e' => $e])
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $envelopes->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
