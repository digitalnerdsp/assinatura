@php
    $user = auth()->user();
    $isAdmin = $user->isSuperAdmin();
    $item = fn (string ...$patterns) => request()->routeIs(...$patterns) ? 'mm-active' : '';
    $docsOpen = request()->routeIs('envelopes.*', 'documents.*');
    $cfgOpen = request()->routeIs('profile.*', 'company.*', 'api-keys.*', 'webhooks.*');
    $helpUrl = \App\Models\Setting::helpUrl();
@endphp
<ul class="metismenu" id="menu">
    <li class="{{ $item('dashboard') }}"><a href="{{ route('dashboard') }}"><div class="parent-icon"><i class="bx bx-home-alt"></i></div><div class="menu-title">Painel</div></a></li>

    <li class="{{ $docsOpen ? 'mm-active' : '' }}">
        <a class="has-arrow" href="javascript:;"><div class="parent-icon"><i class="bx bx-folder-open"></i></div><div class="menu-title">Documentos</div></a>
        <ul class="mm-collapse {{ $docsOpen ? 'mm-show' : '' }}">
            <li class="{{ $item('envelopes.index', 'envelopes.show', 'envelopes.create') }}"><a href="{{ route('envelopes.index') }}"><i class="bx bx-right-arrow-alt"></i>Documentos criados</a></li>
            <li class="{{ $item('documents.folders') }}"><a href="{{ route('documents.folders') }}"><i class="bx bx-right-arrow-alt"></i>Pastas e etiquetas</a></li>
            <li class="{{ $item('documents.mine') }}"><a href="{{ route('documents.mine') }}"><i class="bx bx-right-arrow-alt"></i>Minhas assinaturas</a></li>
            <li class="{{ $item('documents.trash') }}"><a href="{{ route('documents.trash') }}"><i class="bx bx-right-arrow-alt"></i>Lixeira</a></li>
        </ul>
    </li>

    <li class="{{ $item('templates.*') }}"><a href="{{ route('templates.index') }}"><div class="parent-icon"><i class="bx bx-copy-alt"></i></div><div class="menu-title">Modelos</div></a></li>

    <li class="{{ $item('contacts.*') }}"><a href="{{ route('contacts.index') }}"><div class="parent-icon"><i class="bx bx-id-card"></i></div><div class="menu-title">Contatos</div></a></li>

    <li class="{{ $cfgOpen ? 'mm-active' : '' }}">
        <a class="has-arrow" href="javascript:;"><div class="parent-icon"><i class="bx bx-cog"></i></div><div class="menu-title">Configurações</div></a>
        <ul class="mm-collapse {{ $cfgOpen ? 'mm-show' : '' }}">
            <li class="{{ $item('profile.*') }}"><a href="{{ route('profile.edit') }}"><i class="bx bx-right-arrow-alt"></i>Meu perfil</a></li>
            <li class="{{ $item('company.*') }}"><a href="{{ route('company.signing.edit') }}"><i class="bx bx-right-arrow-alt"></i>Assinatura da empresa</a></li>
            <li class="{{ $item('api-keys.*') }}"><a href="{{ route('api-keys.index') }}"><i class="bx bx-right-arrow-alt"></i>API</a></li>
            <li class="{{ $item('webhooks.*') }}"><a href="{{ route('webhooks.index') }}"><i class="bx bx-right-arrow-alt"></i>Webhooks</a></li>
        </ul>
    </li>

    <li><a href="{{ route('verify.index') }}" target="_blank" rel="noopener"><div class="parent-icon"><i class="bx bx-badge-check"></i></div><div class="menu-title">Verificar documento</div></a></li>
    @if ($helpUrl)
        <li><a href="{{ $helpUrl }}" target="_blank" rel="noopener"><div class="parent-icon"><i class="bx bx-help-circle"></i></div><div class="menu-title">Central de ajuda</div></a></li>
    @endif

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
