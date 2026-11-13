@extends('layouts.app')
@section('title', 'Documentos criados')
@section('section', 'Documentos')
@section('actions')<a href="{{ route('envelopes.create') }}" class="btn btn-primary"><i class="bx bx-plus"></i> Criar documento</a>@endsection
@section('content')
<div class="card radius-10">
    <div class="card-body">
        <form method="get" action="{{ route('envelopes.index') }}" class="row g-2 mb-3">
            @if ($onlyArchived)<input type="hidden" name="arquivados" value="1">@endif
            <div class="col-12 col-md-4"><input type="search" class="form-control" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Buscar por título, signatário, e-mail ou código"></div>
            <div class="col-6 col-md-2">
                <select class="form-select" name="status">
                    <option value="">Todos os status</option>
                    @foreach (\App\Enums\EnvelopeStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ __('status.'.$s->value) }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select" name="folder">
                    <option value="">Todas as pastas</option>
                    <option value="none" @selected(($filters['folder'] ?? '') === 'none')>Sem pasta</option>
                    @foreach ($folders as $f)<option value="{{ $f->id }}" @selected(($filters['folder'] ?? '') === $f->id)>{{ $f->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select" name="tag">
                    <option value="">Todas as etiquetas</option>
                    @foreach ($tags as $t)<option value="{{ $t->id }}" @selected(($filters['tag'] ?? '') === $t->id)>{{ $t->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-auto d-flex gap-2">
                <button class="btn btn-outline-primary"><i class="bx bx-search"></i> Filtrar</button>
                @if (array_filter($filters))<a class="btn btn-outline-secondary" href="{{ route('envelopes.index') }}">Limpar</a>@endif
            </div>
        </form>

        <div class="mb-3 small">
            @if ($onlyArchived)<strong>Arquivados</strong> · <a href="{{ route('envelopes.index') }}">Ver ativos</a>@else<a href="{{ route('envelopes.index', ['arquivados' => 1]) }}"><i class="bx bx-archive"></i> Ver arquivados</a>@endif
        </div>

        @if ($envelopes->isEmpty())
            <div class="text-center py-5">
                <i class="bx bx-file fs-1 text-secondary"></i>
                <p class="text-secondary mt-2">{{ array_filter($filters) ? 'Nenhum documento encontrado com esses filtros.' : 'Nenhum documento ainda. Crie o primeiro para enviar um PDF para assinatura.' }}</p>
                @unless (array_filter($filters))<a class="btn btn-primary" href="{{ route('envelopes.create') }}">Criar documento</a>@endunless
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Título</th><th>Pasta / etiquetas</th><th>Status</th><th>Signatários</th><th>Criado em</th></tr></thead>
                    <tbody>
                    @foreach ($envelopes as $e)
                        <tr>
                            <td><a href="{{ route('envelopes.show', $e) }}" class="fw-semibold">{{ $e->title }}</a></td>
                            <td>
                                @if ($e->folder)<span class="text-secondary small me-1"><i class="bx bx-folder"></i> {{ $e->folder->name }}</span>@endif
                                @foreach ($e->tags as $t)<span class="badge bg-{{ $t->color }} me-1">{{ $t->name }}</span>@endforeach
                            </td>
                            <td>@include('partials.status-badge', ['status' => $e->status->value])</td>
                            <td>{{ $e->signers_count }}</td>
                            <td>{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $envelopes->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection
