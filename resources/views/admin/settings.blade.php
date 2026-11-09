@extends('layouts.app')
@section('title', 'Configurações')
@section('content')
<p><a href="{{ route('admin.dashboard') }}">← Administração</a></p>
<h1>Configurações da plataforma</h1>
<div class="card">
    <form method="post" action="{{ route('admin.settings.update') }}">
        @csrf @method('PUT')
        <label style="font-weight:400"><input type="checkbox" name="registration_open" value="1" @checked($registrationOpen)>
            Permitir novos cadastros de empresas em <span class="mono">/cadastro</span></label>
        <p class="muted">Desmarque para aceitar apenas clientes que você já cadastrou. Quem já tem conta continua entrando normalmente.</p>
        <p><button class="btn">Salvar</button></p>
    </form>
</div>
<div class="card">
    <h2>Super administradores</h2>
    <p class="muted">Definidos pela variável de ambiente <span class="mono">SUPER_ADMIN_EMAILS</span> (separe vários por vírgula).</p>
    <ul>@foreach ($admins as $a)<li>{{ $a }}</li>@endforeach</ul>
</div>
@endsection
