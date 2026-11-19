<!doctype html>
<html lang="pt-BR" class="preset-1" data-pc-sidebar-caption="true" data-pc-layout="vertical" data-pc-direction="ltr" dir="ltr" data-pc-theme="light"@if (! empty($brand['primary'])) style="--primary-500: {{ $brand['primary'] }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Painel') · {{ $brand['name'] }}</title>
    <script>
        // Aplica o tema salvo antes de pintar a tela (evita piscar claro → escuro).
        (function () {
            var dark = false;
            try { dark = localStorage.getItem('theme') === 'dark'; } catch (e) {}
            var h = document.documentElement;
            h.setAttribute('data-pc-theme', dark ? 'dark' : 'light');
            h.classList.add(dark ? 'dark-theme' : 'light-theme');
        })();
    </script>
    <link rel="icon" href="{{ $themeAssets }}/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="{{ $themeAssets }}/fonts/inter/inter.css">
    <link rel="stylesheet" href="{{ $themeAssets }}/fonts/tabler-icons.min.css">
    @foreach ($legacyCss as $css)
        <link rel="stylesheet" href="{{ $css }}">
    @endforeach
    <link rel="stylesheet" href="{{ $themeAssets }}/css/style.css">
    <link rel="stylesheet" href="{{ $themeAssets }}/css/able-app.css">
</head>
<body>
<a href="#ab-main" class="ab-skip">Pular para o conteúdo</a>

<nav class="pc-sidebar" aria-label="Menu principal">
    <div class="navbar-wrapper">
        <div class="m-header flex items-center py-4 px-6">
            <a href="{{ route('dashboard') }}" class="b-brand ab-brand">
                @if (! empty($brand['logo_url']))
                    <img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}">
                @else
                    <span class="ab-brand-mark" aria-hidden="true"><img src="{{ asset('assets/images/logo-assinaja-mark.svg') }}" alt="" width="30" height="24"></span>
                    <span class="ab-brand-name">{{ $brand['name'] }}</span>
                @endif
            </a>
        </div>
        <div class="navbar-content">
            @if ($user)
                <div class="ab-user-card">
                    <img src="{{ $user->avatarUrl() }}" alt="">
                    <div class="ab-user-meta">
                        <p class="ab-user-name">{{ $user->name }}</p>
                        <small>{{ $isSuper ? 'Super admin' : ($isAdmin ? 'Equipe da plataforma' : $user->email) }}</small>
                    </div>
                </div>
            @endif
            <ul class="pc-navbar">
                @if (($menu[0]['group'] ?? null) === null)
                    <li class="pc-item pc-caption"><label>Navegação</label></li>
                @endif
                @foreach ($menu as $item)
                    @if (! empty($item['group']))
                        <li class="pc-item pc-caption"><label>{{ $item['group'] }}</label></li>
                    @endif
                    @if (! empty($item['children']))
                        <li class="pc-item pc-hasmenu{{ $item['active'] ? ' active pc-trigger' : '' }}">
                            <a href="{{ $item['url'] }}" class="pc-link" role="button" data-ab-submenu aria-expanded="{{ $item['active'] ? 'true' : 'false' }}">
                                <span class="pc-micon"><i class="ti ti-{{ ['home' => 'home', 'folder' => 'folder', 'templates' => 'template', 'contacts' => 'notebook', 'calendar' => 'calendar', 'reports' => 'chart-bar', 'settings' => 'settings', 'verify' => 'shield-check', 'help' => 'help', 'platform' => 'dashboard', 'tenants' => 'building', 'subscriptions' => 'receipt', 'health' => 'activity', 'sealing' => 'certificate', 'plans' => 'package', 'platform-users' => 'users', 'themes' => 'palette', 'mail' => 'mail', 'platform-settings' => 'adjustments', 'assistant' => 'message-chatbot'][$item['icon']] ?? 'point' }}" aria-hidden="true"></i></span>
                                <span class="pc-mtext">{{ $item['label'] }}</span>
                                <span class="pc-arrow" aria-hidden="true"><i class="ti ti-chevron-right"></i></span>
                            </a>
                            <ul class="pc-submenu">
                                @foreach ($item['children'] as $child)
                                    <li class="pc-item{{ $child['active'] ? ' active' : '' }}">
                                        <a class="pc-link" href="{{ $child['url'] }}"@if ($child['active']) aria-current="page"@endif>{{ $child['label'] }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @else
                        <li class="pc-item{{ $item['active'] ? ' active' : '' }}">
                            <a href="{{ $item['url'] }}" class="pc-link"@if ($item['active']) aria-current="page"@endif @if ($item['external']) target="_blank" rel="noopener noreferrer"@endif>
                                <span class="pc-micon"><i class="ti ti-{{ ['home' => 'home', 'folder' => 'folder', 'templates' => 'template', 'contacts' => 'notebook', 'calendar' => 'calendar', 'reports' => 'chart-bar', 'settings' => 'settings', 'verify' => 'shield-check', 'help' => 'help', 'platform' => 'dashboard', 'tenants' => 'building', 'subscriptions' => 'receipt', 'health' => 'activity', 'sealing' => 'certificate', 'plans' => 'package', 'platform-users' => 'users', 'themes' => 'palette', 'mail' => 'mail', 'platform-settings' => 'adjustments', 'assistant' => 'message-chatbot'][$item['icon']] ?? 'point' }}" aria-hidden="true"></i></span>
                                <span class="pc-mtext">{{ $item['label'] }}</span>
                                @if ($item['external'])
                                    <i class="ti ti-external-link ab-ext" aria-hidden="true"></i><span class="sr-only">(abre em nova aba)</span>
                                @endif
                            </a>
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    </div>
</nav>

<header class="pc-header">
    <div class="header-wrapper flex max-sm:px-[15px] px-[25px] grow">
        <div class="me-auto">
            <ul class="ab-head-list">
                <li class="pc-h-item max-lg:hidden lg:inline-flex">
                    <a href="#" class="pc-head-link ltr:!ml-0" id="sidebar-hide" role="button" aria-label="Recolher ou mostrar o menu" aria-expanded="true"><i class="ti ti-menu-2" aria-hidden="true"></i></a>
                </li>
                <li class="pc-h-item lg:hidden">
                    <a href="#" class="pc-head-link ltr:!ml-0" id="mobile-collapse" role="button" aria-label="Abrir o menu" aria-expanded="false"><i class="ti ti-menu-2" aria-hidden="true"></i></a>
                </li>
                <li class="pc-h-item max-md:hidden md:inline-flex">
                    <form class="ab-search" role="search" method="get" action="{{ route('envelopes.index') }}">
                        <i class="ti ti-search" aria-hidden="true"></i>
                        <input type="search" name="q" class="form-control" placeholder="Buscar documentos" aria-label="Buscar documentos" maxlength="100">
                    </form>
                </li>
            </ul>
        </div>
        <div class="ms-auto">
            <ul class="ab-head-list">
                <li class="pc-h-item">
                    <a class="btn btn-primary ab-create" href="{{ route('envelopes.create') }}"><i class="ti ti-plus" aria-hidden="true"></i><span class="ab-create-label">Criar documento</span></a>
                </li>
                <li class="pc-h-item">
                    <button type="button" class="pc-head-link" id="ab-theme-toggle" aria-label="Usar tema escuro" title="Usar tema escuro"><i class="ti ti-moon" aria-hidden="true"></i></button>
                </li>
                @if ($user)
                    <li class="dropdown pc-h-item">
                        <a class="pc-head-link dropdown-toggle arrow-none me-0" data-pc-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false" aria-label="Menu do usuário">
                            <img src="{{ $user->avatarUrl() }}" alt="" class="ab-avatar">
                        </a>
                        <div class="dropdown-menu dropdown-user-profile dropdown-menu-end p-2">
                            <div class="ab-dd-head">
                                <img src="{{ $user->avatarUrl() }}" alt="">
                                <div class="ab-dd-meta">
                                    <h6>{{ $user->name }}</h6>
                                    <span>{{ $user->email }}</span>
                                </div>
                            </div>
                            <hr class="ab-dd-sep">
                            <a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="ti ti-user" aria-hidden="true"></i><span>Meu perfil</span></a>
                            <a class="dropdown-item" href="{{ route('dashboard') }}"><i class="ti ti-home" aria-hidden="true"></i><span>Painel</span></a>
                            @if ($user->role === 'owner')
                                <a class="dropdown-item" href="{{ route('api-keys.index') }}"><i class="ti ti-code" aria-hidden="true"></i><span>Chaves de API</span></a>
                            @endif
                            <hr class="ab-dd-sep">
                            <form method="post" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item"><i class="ti ti-logout" aria-hidden="true"></i><span>Sair</span></button>
                            </form>
                        </div>
                    </li>
                @endif
            </ul>
        </div>
    </div>
</header>

<div class="pc-container">
    <main class="pc-content" id="ab-main" tabindex="-1">
        <div class="page-header">
            <div class="ab-page-head">
                <div>
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Início</a></li>
                        <li class="breadcrumb-item" aria-current="page">@yield('section', 'Assinaturas')</li>
                    </ul>
                    <h1 class="ab-page-title">@yield('title', 'Painel')</h1>
                </div>
                @hasSection('actions')
                    @hasSection('native')
                        <div class="ab-actions">@yield('actions')</div>
                    @else
                        <div class="ab-actions legacy-scope">@yield('actions')</div>
                    @endif
                @endif
            </div>
        </div>

        <div class="ab-flash legacy-scope">{{ $flash }}</div>

        @hasSection('native')
            @yield('content')
        @else
            <div class="legacy-scope">@yield('content')</div>
        @endif
    </main>
</div>

<footer class="pc-footer">
    <div class="ab-footer">
        <span>© {{ date('Y') }} {{ $brand['name'] }}</span>
        <span>Assinatura eletrônica de documentos</span>
    </div>
</footer>

<script src="{{ $themeAssets }}/vendor/simplebar/simplebar.min.js"></script>
@hasSection('charts')
    <script src="{{ $themeAssets }}/vendor/apexcharts/apexcharts.min.js"></script>
@endif
<script src="{{ $themeAssets }}/js/able.js"></script>
{{ $legacyScripts }}
</body>
</html>
