@extends('layouts.app')
@section('title', 'Entrar')
@section('width', 'narrow')
@section('content')
<div class="card">
    <h1>Entrar</h1>
    <form method="post" action="{{ route('login') }}">
        @csrf
        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password">Senha</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        <p><button class="btn">Entrar</button></p>
    </form>
    <p class="muted">Ainda não tem conta? <a href="{{ route('register') }}">Criar conta</a></p>
</div>
@endsection
