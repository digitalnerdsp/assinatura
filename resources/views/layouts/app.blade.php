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
    $avatar = 'assets/images/avatars/avatar-'.((crc32($u->email) % 5) + 1).'.png';
@endphp
<div class="wrapper">
    <div class="sidebar-wrapper" data-simplebar="true">
        <div class="sidebar-header">
            <div><span class="brand-badge"><i class="bx bx-pen"></i></span></div>
            <div><h4 class="logo-text">{{ config('app.name') }}</h4></div>
            <div class="toggle-icon ms-auto"><i class="bx bx-arrow-back"></i></div>
        </div>
        <ul class="metismenu" id="menu">
            <li class="{{ $item('dashboard') }}"><a href="{{ route('dashboard') }}"><div class="parent-icon"><i class="bx bx-home-alt"></i></div><div class="menu-title">Painel</div></a></li>
            <li class="menu-label">Assinaturas</li>
            <li class="{{ $item('envelopes.index') }}{{ $item('envelopes.show') }}"><a href="{{ route('envelopes.index') }}"><div class="parent-icon"><i class="bx bx-file"></i></div><div class="menu-title">Envelopes</div></a></li>
            <li class="{{ $item('envelopes.create') }}"><a href="{{ route('envelopes.create') }}"><div class="parent-icon"><i class="bx bx-plus-circle"></i></div><div class="menu-title">Novo envelope</div></a></li>
            <li class="{{ $item('templates.*') }}"><a href="{{ route('templates.index') }}"><div class="parent-icon"><i class="bx bx-copy-alt"></i></div><div class="menu-title">Modelos</div></a></li>
            <li class="{{ $item('organization.*') }}"><a href="{{ route('organization.index') }}"><div class="parent-icon"><i class="bx bx-folder"></i></div><div class="menu-title">Pastas e etiquetas</div></a></li>
            <li><a href="{{ route('verify.index') }}" target="_blank"><div class="parent-icon"><i class="bx bx-badge-check"></i></div><div class="menu-title">Verificar documento</div></a></li>
            <li class="menu-label">Integração</li>
            <li class="{{ $item('api-keys.*') }}"><a href="{{ route('api-keys.index') }}"><div class="parent-icon"><i class="bx bx-code-alt"></i></div><div class="menu-title">API</div></a></li>
            <li class="{{ $item('webhooks.*') }}"><a href="{{ route('webhooks.index') }}"><div class="parent-icon"><i class="bx bx-transfer-alt"></i></div><div class="menu-title">Webhooks</div></a></li>
            @if ($isAdmin)
                <li class="menu-label">Plataforma</li>
                <li class="{{ $item('admin.dashboard') }}"><a href="{{ route('admin.dashboard') }}"><div class="parent-icon"><i class="bx bx-line-chart"></i></div><div class="menu-title">Visão geral</div></a></li>
                <li class="{{ $item('admin.tenants.*') }}"><a href="{{ route('admin.tenants.index') }}"><div class="parent-icon"><i class="bx bx-buildings"></i></div><div class="menu-title">Clientes</div></a></li>
                <li class="{{ $item('admin.health*') }}"><a href="{{ route('admin.health') }}"><div class="parent-icon"><i class="bx bx-pulse"></i></div><div class="menu-title">Saúde do sistema</div></a></li>
                <li class="{{ $item('admin.sealing*') }}"><a href="{{ route('admin.sealing') }}"><div class="parent-icon"><i class="bx bx-shield-quarter"></i></div><div class="menu-title">Selagem do PDF</div></a></li>
                <li class="{{ $item('admin.mail*') }}"><a href="{{ route('admin.mail') }}"><div class="parent-icon"><i class="bx bx-envelope"></i></div><div class="menu-title">E-mail (SMTP)</div></a></li>
                <li class="{{ $item('admin.settings*') }}"><a href="{{ route('admin.settings') }}"><div class="parent-icon"><i class="bx bx-cog"></i></div><div class="menu-title">Configurações</div></a></li>
            @endif
        </ul>
    </div>

    <header>
        <div class="topbar d-flex align-items-center">
            <nav class="navbar navbar-expand gap-3">
                <div class="mobile-toggle-menu"><i class="bx bx-menu"></i></div>
                <div class="top-menu ms-auto">
                    <ul class="navbar-nav align-items-center gap-1">
                        <li class="nav-item dark-mode d-none d-sm-flex"><a class="nav-link dark-mode-icon" href="javascript:;" title="Tema claro/escuro"><i class="bx bx-moon"></i></a></li>
                    </ul>
                </div>
                <div class="user-box dropdown px-3">
                    <a class="d-flex align-items-center nav-link dropdown-toggle gap-3 dropdown-toggle-nocaret" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="{{ asset($avatar) }}" class="user-img" alt="">
                        <div class="user-info">
                            <p class="user-name mb-0">{{ $u->name }}</p>
                            <p class="designattion mb-0">{{ $isAdmin ? 'Super admin' : ($u->tenant?->name ?? '—') }}</p>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
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
            <div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
                <div class="breadcrumb-title pe-3">@yield('section', 'Assinaturas')</div>
                <div class="ps-3">
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
