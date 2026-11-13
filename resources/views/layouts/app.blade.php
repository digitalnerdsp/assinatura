<!doctype html>
<html lang="pt-BR">
<head>
    @include('layouts.partials.head')
    <title>@yield('title', 'Painel') · {{ config('app.name') }}</title>
</head>
<body>
@php
    $u = auth()->user();
    $isAdmin = $u->isSuperAdmin();
    $item = fn (string $pattern) => request()->routeIs($pattern) ? 'mm-active' : '';
@endphp
<div class="wrapper">
    <div class="sidebar-wrapper" data-simplebar="true">
        <div class="sidebar-header">
            <div><span class="brand-badge"><i class="bx bx-pen"></i></span></div>
            <div><h4 class="logo-text">{{ config('app.name') }}</h4></div>
            <div class="toggle-icon ms-auto"><i class="bx bx-arrow-back"></i></div>
        </div>
        @include('layouts.partials.sidebar')
    </div>

    <header>
        <div class="topbar d-flex align-items-center">
            <nav class="navbar navbar-expand gap-3">
                <div class="mobile-toggle-menu"><i class="bx bx-menu"></i></div>
                <div class="top-menu ms-auto">
                    <ul class="navbar-nav align-items-center gap-1">
                        <li class="nav-item d-none d-sm-flex me-2"><a class="btn btn-primary" href="{{ route('envelopes.create') }}"><i class="bx bx-plus"></i> Criar documento</a></li>
                        <li class="nav-item dark-mode d-none d-sm-flex"><a class="nav-link dark-mode-icon" href="javascript:;" title="Tema claro/escuro"><i class="bx bx-moon"></i></a></li>
                    </ul>
                </div>
                <div class="user-box dropdown px-3">
                    <a class="d-flex align-items-center nav-link dropdown-toggle gap-3 dropdown-toggle-nocaret" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="{{ $u->avatarUrl() }}" class="user-img" alt="" style="object-fit:cover">
                        <div class="user-info">
                            <p class="user-name mb-0">{{ $u->name }}</p>
                            <p class="designattion mb-0">{{ $isAdmin ? 'Super admin' : ($u->tenant?->name ?? '—') }}</p>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item d-flex align-items-center" href="{{ route('profile.edit') }}"><i class="bx bx-user fs-5"></i><span>Meu perfil</span></a></li>
                        <li><a class="dropdown-item d-flex align-items-center" href="{{ route('dashboard') }}"><i class="bx bx-home-circle fs-5"></i><span>Painel</span></a></li>
                        <li><a class="dropdown-item d-flex align-items-center" href="{{ route('api-keys.index') }}"><i class="bx bx-code-alt fs-5"></i><span>Chaves de API</span></a></li>
                        <li><div class="dropdown-divider mb-0"></div></li>
                        <li>
                            <form method="post" action="{{ route('logout') }}">@csrf
                                <button class="dropdown-item d-flex align-items-center"><i class="bx bx-log-out-circle"></i><span>Sair</span></button>
                            </form>
                        </li>
                    </ul>
                </div>
            </nav>
        </div>
    </header>

    <div class="page-wrapper">
        <div class="page-content">
            <div class="page-breadcrumb d-flex flex-wrap align-items-center gap-2 mb-3">
                <div class="breadcrumb-title pe-3">@yield('section', 'Assinaturas')</div>
                <div class="ps-3 d-none d-sm-block">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0 p-0">
                            <li class="breadcrumb-item"><a href="{{ route('dashboard') }}"><i class="bx bx-home-alt"></i></a></li>
                            <li class="breadcrumb-item active" aria-current="page">@yield('title', 'Painel')</li>
                        </ol>
                    </nav>
                </div>
                <div class="ms-auto">@yield('actions')</div>
            </div>

            @include('layouts.partials.flash')
            @yield('content')
        </div>
    </div>
    <div class="overlay toggle-icon"></div>
    <a href="javascript:;" class="back-to-top"><i class="bx bxs-up-arrow-alt"></i></a>
    <footer class="page-footer"><p class="mb-0">© {{ date('Y') }} {{ config('app.name') }}. Todos os direitos reservados.</p></footer>
</div>
@include('layouts.partials.scripts')
</body>
</html>
