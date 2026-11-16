<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Painel') · {{ $brand['name'] }}</title>
    <script>try { if (localStorage.getItem('theme') === 'dark') { document.documentElement.classList.add('dark-theme'); } } catch (e) {}</script>
    @foreach ($legacyCss as $href)
        <link rel="stylesheet" href="{{ $href }}">
    @endforeach
    <link rel="stylesheet" href="{{ $themeAssets }}/vendor/line-awesome/css/line-awesome.min.css">
    <link rel="stylesheet" href="{{ $themeAssets }}/vendor/metismenu/metisMenu.min.css">
    <link rel="stylesheet" href="{{ $themeAssets }}/css/style.css">
    <link rel="stylesheet" href="{{ $themeAssets }}/css/w3crm.css">
</head>
<body data-typography="poppins" data-theme-version="light" data-layout="vertical" data-nav-headerbg="color_4" data-headerbg="color_4" data-sidebarbg="w3" data-sidebar-style="full" data-sidebar-position="fixed" data-header-position="fixed" data-container="wide" data-primary="color_1"
      @if ($brand['primary']) style="--primary: {{ $brand['primary'] }};" @endif>
<script>if (document.documentElement.classList.contains('dark-theme')) { document.body.setAttribute('data-theme-version', 'dark'); }</script>
<a class="w3-skip" href="#conteudo">Ir para o conteúdo</a>

<div id="main-wrapper">
    <script>try { if (localStorage.getItem('w3crm-collapsed') === '1') { document.getElementById('main-wrapper').classList.add('w3-collapsed'); } } catch (e) {}</script>
    {{-- Logo / nome da marca + botão de recolher o menu --}}
    <div class="nav-header">
        <a href="{{ route('dashboard') }}" class="brand-logo w3-brand" aria-label="{{ $brand['name'] }} — painel">
            @if ($brand['logo_url'])
                <img src="{{ $brand['logo_url'] }}" alt="" class="w3-brand-img">
            @else
                <span class="w3-brand-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($brand['name'], 0, 1)) }}</span>
            @endif
            <span class="w3-brand-name">{{ $brand['name'] }}</span>
        </a>
        <button type="button" class="nav-control w3-nav-control" aria-label="Abrir ou recolher o menu" aria-controls="w3-sidebar" aria-expanded="false">
            <span class="hamburger"><span class="line"></span><span class="line"></span><span class="line"></span></span>
        </button>
    </div>

    {{-- Topo --}}
    <div class="header">
        <div class="header-content">
            <nav class="navbar navbar-expand" aria-label="Barra superior">
                <div class="navbar-collapse w3-topbar">
                    <div class="header-left w3-topbar-title">
                        <span class="w3-topbar-heading">@yield('title', 'Painel')</span>
                    </div>
                    <ul class="navbar-nav header-right w3-topbar-actions">
                        <li class="nav-item">
                            <a class="w3-btn w3-btn--primary w3-create" href="{{ route('envelopes.create') }}">
                                <i class="las la-plus" aria-hidden="true"></i><span class="w3-create-text">Criar documento</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <button type="button" class="w3-icon-btn w3-theme-toggle" aria-label="Alternar tema claro/escuro" aria-pressed="false" title="Tema claro/escuro">
                                <i class="las la-moon" aria-hidden="true"></i>
                            </button>
                        </li>
                        <li class="nav-item w3-dd">
                            <button type="button" class="w3-user" data-w3-dropdown="w3-user-menu" aria-haspopup="true" aria-expanded="false">
                                <img src="{{ $user->avatarUrl() }}" alt="" class="w3-avatar" width="34" height="34">
                                <span class="w3-user-info">
                                    <span class="w3-user-name">{{ $user->name }}</span>
                                    <span class="w3-user-sub">{{ $isAdmin ? 'Equipe da plataforma' : $user->email }}</span>
                                </span>
                                <i class="las la-angle-down w3-user-caret" aria-hidden="true"></i>
                            </button>
                            <div id="w3-user-menu" class="w3-dd-menu" hidden>
                                <div class="w3-dd-head">
                                    <img src="{{ $user->avatarUrl() }}" alt="" class="w3-avatar w3-avatar--lg" width="44" height="44">
                                    <div>
                                        <div class="w3-dd-name">{{ $user->name }}</div>
                                        <div class="w3-dd-mail">{{ $user->email }}</div>
                                    </div>
                                </div>
                                <a class="w3-dd-item" href="{{ route('profile.edit') }}"><i class="las la-user" aria-hidden="true"></i>Meu perfil</a>
                                <a class="w3-dd-item" href="{{ route('dashboard') }}"><i class="las la-home" aria-hidden="true"></i>Painel</a>
                                @if ($user->role === 'owner')
                                    <a class="w3-dd-item" href="{{ route('api-keys.index') }}"><i class="las la-key" aria-hidden="true"></i>Chaves de API</a>
                                @endif
                                <form method="post" action="{{ route('logout') }}" class="w3-dd-foot">
                                    @csrf
                                    <button type="submit" class="w3-dd-item w3-dd-item--danger"><i class="las la-sign-out-alt" aria-hidden="true"></i>Sair</button>
                                </form>
                            </div>
                        </li>
                    </ul>
                </div>
            </nav>
        </div>
    </div>

    {{-- Menu lateral (gerado de $menu) --}}
    <div class="deznav" id="w3-sidebar">
        <div class="deznav-scroll">
            <nav aria-label="Menu principal">
                @foreach ([[
                    'home' => 'la-home', 'folder' => 'la-folder-open', 'templates' => 'la-copy', 'contacts' => 'la-address-book',
                    'calendar' => 'la-calendar', 'reports' => 'la-chart-bar', 'settings' => 'la-cog', 'verify' => 'la-shield-alt',
                    'help' => 'la-question-circle', 'platform' => 'la-tachometer-alt', 'tenants' => 'la-building',
                    'subscriptions' => 'la-credit-card', 'health' => 'la-heartbeat', 'sealing' => 'la-stamp', 'plans' => 'la-layer-group',
                    'platform-users' => 'la-users-cog', 'themes' => 'la-palette', 'mail' => 'la-envelope', 'platform-settings' => 'la-sliders-h',
                ]] as $icons)
                <ul class="metismenu" id="w3menu">
                    @foreach ($menu as $item)
                        @if ($item['group'])
                            <li class="menu-title">{{ $item['group'] }}</li>
                        @endif
                        @if (count($item['children']) > 0)
                            <li class="{{ $item['active'] ? 'mm-active' : '' }}">
                                <a class="has-arrow" href="#" role="button" aria-expanded="{{ $item['active'] ? 'true' : 'false' }}">
                                    <span class="menu-icon"><i class="las {{ $icons[$item['icon']] ?? 'la-circle' }}" aria-hidden="true"></i></span>
                                    <span class="nav-text">{{ $item['label'] }}</span>
                                </a>
                                <ul class="sub-menu mm-collapse {{ $item['active'] ? 'mm-show' : '' }}">
                                    @foreach ($item['children'] as $child)
                                        <li class="{{ $child['active'] ? 'mm-active' : '' }}">
                                            <a href="{{ $child['url'] }}" class="{{ $child['active'] ? 'mm-active' : '' }}" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @else
                            <li class="{{ $item['active'] ? 'mm-active active-no-child' : '' }}">
                                <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif @if ($item['external']) target="_blank" rel="noopener" @endif>
                                    <span class="menu-icon"><i class="las {{ $icons[$item['icon']] ?? 'la-circle' }}" aria-hidden="true"></i></span>
                                    <span class="nav-text">{{ $item['label'] }}@if ($item['external'])<i class="las la-external-link-alt w3-ext" aria-hidden="true"></i><span class="w3-sr"> (abre em nova aba)</span>@endif</span>
                                </a>
                            </li>
                        @endif
                    @endforeach
                </ul>
                @endforeach
            </nav>
        </div>
    </div>
    <div class="w3-backdrop" aria-hidden="true"></div>

    {{-- Conteúdo --}}
    <main class="content-body" id="conteudo">
        <div class="page-titles w3-page-titles">
            <div class="w3-crumbs">
                <span class="w3-crumb-section">@yield('section', 'Assinaturas')</span>
                <i class="las la-angle-right" aria-hidden="true"></i>
                <h1 class="w3-crumb-title">@yield('title', 'Painel')</h1>
            </div>
            @hasSection('actions')
                <div class="legacy-scope w3-actions">@yield('actions')</div>
            @endif
        </div>

        <div class="container-fluid w3-container">
            <div class="legacy-scope w3-flash">{{ $flash }}</div>
            @hasSection('native')
                @yield('content')
            @else
                <div class="legacy-scope">@yield('content')</div>
            @endif
        </div>

        <footer class="w3-footer">© {{ date('Y') }} {{ $brand['name'] }}. Todos os direitos reservados.</footer>
    </main>
</div>

@hasSection('native')
    <script src="{{ $themeAssets }}/vendor/apexcharts/apexcharts.min.js"></script>
@endif
<script src="{{ $themeAssets }}/js/w3crm.js"></script>
{{ $legacyScripts }}
</body>
</html>
