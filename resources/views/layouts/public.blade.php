<!doctype html>
<html lang="pt-BR">
<head>
    @include('layouts.partials.head')
    <title>@yield('title', 'Assinatura') · {{ \App\Branding\BrandService::forRequest(request())['name'] }}</title>
</head>
<body>
<div class="wrapper" style="min-height:100vh">
    <header class="border-bottom bg-body">
        <div class="container py-2 d-flex align-items-center gap-2" style="max-width:@yield('max', '860px')">
            @if (\App\Branding\BrandService::forRequest(request())['active'])
                @include('layouts.partials.brand-mark')
                <strong class="fs-5">{{ \App\Branding\BrandService::forRequest(request())['name'] }}</strong>
            @else
                <img src="{{ asset('assets/images/logo-assinaja.svg') }}" alt="{{ config('app.name') }}" width="150" height="35" style="height:36px;width:auto">
            @endif
            <span class="ms-auto d-none d-sm-inline text-secondary small"><i class="bx bx-lock-alt"></i> Conexão segura</span>
        </div>
    </header>
    <main class="container py-4" style="max-width:@yield('max', '860px')">
        @include('layouts.partials.flash')
        @yield('content')
    </main>
    <footer class="text-center text-secondary small pb-4">© {{ date('Y') }} {{ \App\Branding\BrandService::forRequest(request())['name'] }}</footer>
</div>
@include('layouts.partials.scripts')
</body>
</html>
