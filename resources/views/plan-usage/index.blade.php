@extends('layouts.app')
@section('title', 'Plano e uso')
@section('section', 'Configurações')
@section('content')
@php
    $status = ['trialing' => ['Em teste', 'bg-info'], 'active' => ['Ativa', 'bg-success'], 'blocked' => ['Bloqueada', 'bg-danger'], 'canceled' => ['Cancelada', 'bg-secondary']][$sub->status];
    $bytes = fn (int $b) => $b >= 1073741824 ? number_format($b / 1073741824, 2, ',', '.').' GB' : number_format($b / 1048576, 1, ',', '.').' MB';
    $bar = function (string $label, string $used, ?string $of, ?int $pct) {
        return compact('label', 'used', 'of', 'pct');
    };
    $rows = [
        $bar('Documentos neste período', (string) $usage['documents']['used'], $usage['documents']['limit'] !== null ? (string) $usage['documents']['limit'] : null, $usage['documents']['limit'] ? min(100, intdiv($usage['documents']['used'] * 100, max(1, $usage['documents']['limit']))) : null),
        $bar('Usuários', (string) $usage['users']['used'], $usage['users']['limit'] !== null ? (string) $usage['users']['limit'] : null, $usage['users']['limit'] ? min(100, intdiv($usage['users']['used'] * 100, max(1, $usage['users']['limit']))) : null),
        $bar('Armazenamento', $bytes($usage['storage']['used_bytes']), $usage['storage']['limit_bytes'] !== null ? $bytes($usage['storage']['limit_bytes']) : null, $usage['storage']['limit_bytes'] ? min(100, intdiv($usage['storage']['used_bytes'] * 100, max(1, $usage['storage']['limit_bytes']))) : null),
    ];
@endphp
<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card radius-10 h-100"><div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><h5 class="mb-0">{{ $sub->plan->name }}</h5><span class="badge {{ $status[1] }}">{{ $status[0] }}</span></div>
            @if ($sub->plan->description)<p class="text-secondary">{{ $sub->plan->description }}</p>@endif
            <p class="small text-secondary">
                @if ($sub->status === 'trialing' && $sub->trial_ends_at)Seu teste vai até <strong>{{ $sub->trial_ends_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</strong>. @endif
                @if ($sub->period_end)Período atual até <strong>{{ $sub->period_end->timezone('America/Sao_Paulo')->format('d/m/Y') }}</strong>. @endif
            </p>
            @foreach ($rows as $r)
                <div class="mb-3">
                    <div class="d-flex justify-content-between flex-wrap fw-semibold"><span>{{ $r['label'] }}</span><span>{{ $r['used'] }}{{ $r['of'] !== null ? ' de '.$r['of'] : ' · sem limite' }}</span></div>
                    @if ($r['pct'] !== null)
                        <div class="progress mt-1" role="progressbar" aria-label="{{ $r['label'] }}" aria-valuenow="{{ $r['pct'] }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar {{ $r['pct'] >= 100 ? 'bg-danger' : ($r['pct'] >= 80 ? 'bg-warning' : 'bg-success') }}" style="width: {{ $r['pct'] }}%"></div></div>
                        <div class="small text-secondary">{{ $r['pct'] }}% usado</div>
                    @endif
                </div>
            @endforeach
            @if ($usage['documents']['credits'] > 0 || ($rules['balance'] ?? 'quota') !== 'quota')<p class="mb-0"><strong>Créditos de documentos:</strong> {{ $usage['documents']['credits'] }}</p>@endif
            <p class="small text-secondary mt-3 mb-0">Para trocar de plano, aumentar limites ou comprar mais documentos, fale com o suporte. A contratação e o pagamento automáticos chegam em breve.</p>
        </div></div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card radius-10 h-100"><div class="card-body">
            <h5 class="mb-2">O que o seu plano inclui</h5>
            <ul class="list-unstyled mb-0">
                @forelse ($features as $key)<li class="mb-1"><i class="bx bx-check text-success"></i> {{ \App\Models\Plan::FEATURE_LABELS[$key] }}</li>@empty<li class="text-secondary">Nenhum recurso extra.</li>@endforelse
            </ul>
        </div></div>
    </div>
    <div class="col-12">
        <div class="card radius-10"><div class="card-body">
            <h5 class="mb-2">Extrato de documentos</h5>
            @include('partials.usage-entries', ['entries' => $entries])
        </div></div>
    </div>
</div>
@endsection
