@extends('layouts.app')
@section('title', 'Planos')
@section('section', 'Plataforma')
@section('actions')<a class="btn btn-primary drive-tap" href="{{ route('admin.plans.create') }}"><i class="bx bx-plus"></i> Novo plano</a>@endsection
@section('content')
@php
    $popper = '{"strategy":"fixed"}';
    $brl = fn (int $c) => 'R$ '.number_format($c / 100, 2, ',', '.');
    $lim = fn ($v) => $v === null ? 'ilimitado' : number_format((int) $v, 0, ',', '.');
@endphp
@error('plan')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
<div class="card radius-10">
    <div class="card-body">
        <p class="text-secondary small">Cada empresa fica em um plano. Os limites, recursos e regras daqui valem para todas as empresas do plano, e podem ser sobrescritos por empresa em <a href="{{ route('admin.subscriptions.index') }}">Assinaturas</a>. O plano marcado como <strong>Padrão</strong> é o das empresas que se cadastram.</p>
        <div class="table-responsive">
            <table class="table align-middle mb-0 drive-docs drive-docs-md">
                <thead class="table-light"><tr><th>Plano</th><th>Limites</th><th>Preço mensal</th><th>Empresas</th><th aria-label="Ações"></th></tr></thead>
                <tbody>
                @foreach ($plans as $p)
                    @php($month = $p->prices->firstWhere('period', 'month'))
                    <tr>
                        <td data-label="Plano" class="fw-semibold">{{ $p->name }}
                            @if ($p->is_default)<span class="badge bg-primary">Padrão</span>@endif
                            @if ($p->isLegacy())<span class="badge bg-secondary">Legado</span>@endif
                            @if (! $p->active)<span class="badge bg-warning">Inativo</span>@endif
                            @if (! $p->is_public && ! $p->isLegacy())<span class="badge bg-info">Oculto</span>@endif
                        </td>
                        <td data-label="Limites" class="small">Documentos: {{ $lim($p->limits['documents'] ?? null) }} · Usuários: {{ $lim($p->limits['users'] ?? null) }} · Armazenamento: {{ isset($p->limits['storage_mb']) ? number_format($p->limits['storage_mb'] / 1024, 1, ',', '.').' GB' : 'ilimitado' }}</td>
                        <td data-label="Preço mensal">{{ $month ? $brl($month->amount_cents) : '—' }}</td>
                        <td data-label="Empresas">{{ $p->subscriptions_count }}</td>
                        <td data-label="Ações" class="text-end">
                            @unless ($p->isLegacy())
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary drive-tap border-0" data-bs-toggle="dropdown" data-bs-popper-config='{{ $popper }}' aria-expanded="false" aria-label="Ações do plano {{ $p->name }}"><i class="bx bx-dots-vertical-rounded"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item drive-tap justify-content-start" href="{{ route('admin.plans.edit', $p) }}"><i class="bx bx-edit me-2"></i>Editar</a></li>
                                        @if ($p->active && ! $p->is_default)
                                            <li><form method="post" action="{{ route('admin.plans.default', $p) }}">@csrf<button class="dropdown-item drive-tap justify-content-start"><i class="bx bx-star me-2"></i>Tornar padrão</button></form></li>
                                        @endif
                                        <li><form method="post" action="{{ route('admin.plans.destroy', $p) }}" data-confirm="{{ $p->subscriptions_count > 0 ? 'Este plano tem empresas: ele será desativado (não apagado). Continuar?' : 'Apagar o plano '.$p->name.'?' }}">@csrf @method('DELETE')<button class="dropdown-item drive-tap justify-content-start text-danger"><i class="bx bx-trash me-2"></i>{{ $p->subscriptions_count > 0 ? 'Desativar' : 'Apagar' }}</button></form></li>
                                    </ul>
                                </div>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('submit', function (e) {
    var m = e.target.dataset.confirm;
    if (m && !window.confirm(m)) { e.preventDefault(); return; }
    var b = e.target.querySelector('button:not([data-bs-dismiss])');
    if (b) { if (b.dataset.sent) { e.preventDefault(); return; } b.dataset.sent = '1'; }
});
</script>
@endpush
