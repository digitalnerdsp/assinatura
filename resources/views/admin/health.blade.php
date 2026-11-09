@extends('layouts.app')
@section('title', 'Saúde do sistema')
@section('section', 'Plataforma')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('admin.health') }}"><i class="bx bx-refresh"></i> Atualizar</a>@endsection
@section('content')
@php($badge = ['ok' => ['success', 'OK'], 'warn' => ['warning text-dark', 'Atenção'], 'fail' => ['danger', 'Falha']])
<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-pulse me-1"></i> Verificações</h6></div>
    <ul class="list-group list-group-flush">
        @foreach ($checks as $c)
            <li class="list-group-item d-flex align-items-start gap-3">
                <span class="badge bg-{{ $badge[$c['status']][0] }}" style="min-width:64px">{{ $badge[$c['status']][1] }}</span>
                <div><strong>{{ $c['label'] }}</strong><div class="text-secondary small">{{ $c['detail'] }}</div></div>
            </li>
        @endforeach
        <li class="list-group-item d-flex align-items-start gap-3">
            <span class="badge bg-{{ $sentry ? 'success' : 'secondary' }}" style="min-width:64px">{{ $sentry ? 'OK' : 'Desligado' }}</span>
            <div><strong>Alertas de erro (Sentry)</strong><div class="text-secondary small">{{ $sentry ? 'Erros são enviados ao Sentry (sem corpo de requisição, cookies ou tokens).' : 'Defina SENTRY_LARAVEL_DSN para receber alertas de erro.' }}</div></div>
        </li>
    </ul>
</div>

<div class="row">
    @foreach ([
        ['Selados (24h)', $metrics['sealed_24h'], 'bx-badge-check', 'success'],
        ['Falhas de selagem (24h)', $metrics['seal_failed_24h'], 'bx-error-circle', $metrics['seal_failed_24h'] ? 'danger' : 'secondary'],
        ['Na fila', $metrics['jobs_pending'], 'bx-layer', 'info'],
        ['Banco de dados', $metrics['db_size'], 'bx-data', 'secondary'],
    ] as [$label, $value, $icon, $color])
        <div class="col-6 col-xl-3">
            <div class="card radius-10"><div class="card-body d-flex align-items-center gap-3">
                <div class="fs-2 text-{{ $color }}"><i class="bx {{ $icon }}"></i></div>
                <div><div class="text-secondary small">{{ $label }}</div><div class="fs-4 fw-semibold">{{ $value }}</div></div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-buildings me-1"></i> Uso por empresa (30 dias)</h6></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Empresa</th><th>Envelopes</th><th>Concluídos</th><th>Selando</th><th>Armazenamento</th><th>Limite mensal</th></tr></thead>
            <tbody>
            @forelse ($metrics['usage'] as $u)
                <tr>
                    <td><a href="{{ route('admin.tenants.show', $u['id']) }}">{{ $u['name'] }}</a>@if ($u['suspended']) <span class="badge bg-danger">suspensa</span>@endif</td>
                    <td>{{ $u['envelopes'] }}</td><td>{{ $u['completed'] }}</td>
                    <td>@if ($u['sealing'])<span class="badge bg-warning text-dark">{{ $u['sealing'] }}</span>@else 0 @endif</td>
                    <td>{{ $u['storage_mb'] }} MB</td>
                    <td>{{ $u['limit'] ?? 'sem limite' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-secondary p-4">Nenhuma empresa ainda.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-error me-1"></i> Últimas falhas de selagem</h6></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Quando</th><th>Envelope</th><th>Motivo</th></tr></thead>
            <tbody>
            @forelse ($metrics['recent_failures'] as $f)
                <tr>
                    <td>{{ $f->occurred_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td>
                    <td class="mono small">{{ $f->envelope_id }}</td>
                    <td class="small">{{ $f->payload['error'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-secondary p-4">Nenhuma falha registrada.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
