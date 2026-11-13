@extends('layouts.app')
@section('title', 'Relatórios')
@section('section', 'Relatórios')
@section('actions')<a class="btn btn-outline-primary drive-tap" href="{{ route('reports.export', $filters) }}"><i class="bx bx-download"></i> Exportar CSV</a>@endsection
@section('content')
@php
    use App\Reports\ReportService;
    $s = $summary;
    $completedWithTime = array_sum($s['buckets']);
    $bucketColors = ['30m' => '#0b63c7', '1h' => '#2a7fd6', '24h' => '#4a96e0', '2d' => '#74b0ea', '5d' => '#9cc7f1', '10d' => '#c3ddf7', 'more' => '#e3eefb'];
@endphp

<div class="card radius-10">
    <div class="card-body">
        <form method="get" action="{{ route('reports.index') }}" class="row g-2 align-items-end">
            <div class="col-6 col-md-2"><label class="form-label" for="r-from">De</label><input type="date" class="form-control" id="r-from" name="from" value="{{ $filters['from'] ?? '' }}"></div>
            <div class="col-6 col-md-2"><label class="form-label" for="r-to">Até</label><input type="date" class="form-control" id="r-to" name="to" value="{{ $filters['to'] ?? '' }}"></div>
            <div class="col-12 col-md-2"><label class="form-label" for="r-folder">Pasta</label>
                <select class="form-select" id="r-folder" name="folder"><option value="">Todas</option>@foreach ($folderPaths as $id => $path)<option value="{{ $id }}" @selected(($filters['folder'] ?? '') === $id)>{{ $path }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label" for="r-tag">Etiqueta</label>
                <select class="form-select" id="r-tag" name="tag"><option value="">Todas</option>@foreach ($tags as $t)<option value="{{ $t->id }}" @selected(($filters['tag'] ?? '') === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label" for="r-template">Modelo</label>
                <select class="form-select" id="r-template" name="template"><option value="">Todos</option>@foreach ($templates as $tp)<option value="{{ $tp->id }}" @selected(($filters['template'] ?? '') === $tp->id)>{{ $tp->name }}</option>@endforeach</select></div>
            <div class="col-12 col-md-2"><label class="form-label" for="r-signer">E-mail do signatário</label><input type="email" class="form-control" id="r-signer" name="signer" value="{{ $filters['signer'] ?? '' }}"></div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary drive-tap"><i class="bx bx-filter-alt"></i> Aplicar</button>
                @if ($filters)<a class="btn btn-outline-secondary drive-tap" href="{{ route('reports.index') }}">Limpar</a>@endif
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    @foreach ([['Documentos', $s['total'], 'bx-file'], ['Assinados', $s['completed'], 'bx-check-circle'], ['Em andamento', $s['in_progress'], 'bx-time-five'], ['Recusados', $s['refused'], 'bx-x-circle'], ['Expirados', $s['expired'], 'bx-calendar-x']] as [$label, $value, $icon])
        <div class="col-6 col-md-4 col-xl">
            <div class="card radius-10 h-100 mb-0"><div class="card-body">
                <div class="d-flex align-items-center gap-2"><i class="bx {{ $icon }} fs-4"></i><span class="fw-semibold">{{ $label }}</span></div>
                <div class="fs-3 fw-bold mt-1">{{ number_format($value, 0, ',', '.') }}</div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="card radius-10">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
            <h5 class="mb-0">Tempo médio de assinatura</h5>
            <span class="fs-4 fw-bold">{{ ReportService::humanDuration($s['avg_seconds']) }}</span>
        </div>
        <p class="small text-secondary">Do envio até a conclusão, considerando {{ $completedWithTime }} {{ $completedWithTime === 1 ? 'documento concluído' : 'documentos concluídos' }}.</p>
        @if ($completedWithTime > 0)
            <div class="d-flex rounded overflow-hidden mb-2" style="height:18px" role="img" aria-label="Distribuição do tempo de assinatura">
                @foreach ($s['buckets'] as $key => $count)
                    @if ($count > 0)<div style="width:{{ $count / $completedWithTime * 100 }}%;background:{{ $bucketColors[$key] }}"></div>@endif
                @endforeach
            </div>
        @endif
        <ul class="list-unstyled d-flex flex-wrap gap-3 mb-0">
            @foreach ($s['buckets'] as $key => $count)
                <li class="d-flex align-items-center gap-1"><span class="d-inline-block rounded-circle border" style="width:12px;height:12px;background:{{ $bucketColors[$key] }}"></span><span>{{ ReportService::BUCKET_LABELS[$key] }}</span> <strong>{{ $count }}</strong></li>
            @endforeach
        </ul>
    </div>
</div>

<div class="card radius-10">
    <div class="card-body">
        <h5 class="mb-3">Documentos</h5>
        @if ($rows->isEmpty())
            <p class="text-secondary mb-0">Nenhum documento com esses filtros.</p>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0 drive-docs">
                    <thead class="table-light"><tr><th>Título</th><th>Situação</th><th>Criado em</th><th>Concluído em</th><th>Tempo</th><th>Pasta</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $e)
                        @php($secs = $e->sent_at && $e->completed_at ? $e->completed_at->getTimestamp() - $e->sent_at->getTimestamp() : null)
                        <tr>
                            <td data-label="Título"><a class="fw-semibold" href="{{ route('envelopes.show', $e) }}">{{ $e->title }}</a></td>
                            <td data-label="Situação">@include('partials.status-badge', ['status' => $e->status->value])</td>
                            <td data-label="Criado em">{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                            <td data-label="Concluído em">{{ $e->completed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—' }}</td>
                            <td data-label="Tempo">{{ $secs === null ? '—' : ReportService::humanDuration($secs) }}</td>
                            <td data-label="Pasta">{{ $folderPaths[$e->folder_id] ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $rows->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection
