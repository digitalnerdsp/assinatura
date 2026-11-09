<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Assinaturas') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body>
@auth
    <header class="top"><div class="in">
        <a class="brand" href="{{ route('dashboard') }}">{{ config('app.name') }}</a>
        <a href="{{ route('envelopes.create') }}">Novo envelope</a>
        <a href="{{ route('api-keys.index') }}">API</a>
        @if (auth()->user()->isSuperAdmin())<a href="{{ route('admin.dashboard') }}">Admin</a>@endif
        <span class="muted">{{ auth()->user()->name }}</span>
        <form method="post" action="{{ route('logout') }}">@csrf <button class="btn ghost sm">Sair</button></form>
    </div></header>
@endauth
<main class="@yield('width')">
    @if (session('status'))<div class="ok">{{ session('status') }}</div>@endif
    @if (session('warning'))<div class="err">{{ session('warning') }}</div>@endif
    @if ($errors->any())<div class="err">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    @yield('content')
</main>
</body>
</html>
