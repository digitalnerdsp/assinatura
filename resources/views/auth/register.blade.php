@extends('layouts.app')
@section('title', 'Criar conta')
@section('width', 'narrow')
@section('content')
<div class="card">
    <h1>Criar conta</h1>
    <form method="post" action="{{ route('register') }}">
        @csrf
        <label for="company">Empresa</label>
        <input id="company" type="text" name="company" value="{{ old('company') }}" required autofocus>
        <label for="name">Seu nome</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name">
        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
        <label for="password">Senha (mín. 10 caracteres)</label>
        <input id="password" type="password" name="password" required minlength="10" autocomplete="new-password">
        <label for="password_confirmation">Confirmar senha</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        <p><button class="btn">Criar conta</button></p>
    </form>
    <p class="muted">Já tem conta? <a href="{{ route('login') }}">Entrar</a></p>
</div>
@endsection
