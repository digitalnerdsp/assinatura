@php
    $__tenant = auth()->user()?->tenant;
    $__top = ($__tenant && ! request()->routeIs('admin.*')) ? \App\Plans\Entitlements::for($__tenant)->highest() : null; // fora das telas da plataforma
@endphp
@if ($__top && $__top['pct'] >= 80)
    <div class="alert {{ $__top['pct'] >= 100 ? 'alert-danger' : 'alert-warning' }} border-0 d-flex align-items-center gap-2" role="alert">
        <i class="bx {{ $__top['pct'] >= 100 ? 'bx-error-circle' : 'bx-info-circle' }} fs-4"></i>
        <div>
            @if ($__top['pct'] >= 100)
                <strong>Você chegou a 100% do limite de {{ $__top['label'] }}.</strong> Novos documentos não podem ser criados nem enviados até liberar espaço ou trocar de plano. Quem já recebeu continua assinando.
            @else
                <strong>Você já usou {{ $__top['pct'] }}% do limite de {{ $__top['label'] }} do seu plano.</strong> Ao chegar a 100%, novos documentos deixam de ser enviados.
            @endif
            @if (\Illuminate\Support\Facades\Route::has('plan-usage.index'))<a class="fw-bold ms-1 plan-banner-link" href="{{ route('plan-usage.index') }}">Ver plano e uso</a>@endif
        </div>
    </div>
@endif
