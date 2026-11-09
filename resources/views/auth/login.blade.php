@extends('layouts.auth')
@section('title', 'Entrar')
@section('subtitle', 'Acesse sua conta')
@section('content')
<form class="row g-3" method="post" action="{{ route('login') }}">
    @csrf
    <div class="col-12">
        <label for="email" class="form-label">E-mail</label>
        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" placeholder="voce@empresa.com" required autofocus autocomplete="username">
    </div>
    <div class="col-12">
        <label for="password" class="form-label">Senha</label>
        <div class="input-group js-password">
            <input type="password" class="form-control border-end-0" id="password" name="password" placeholder="Sua senha" required autocomplete="current-password">
            <a href="javascript:;" class="input-group-text bg-transparent"><i class="bx bx-hide"></i></a>
        </div>
    </div>
    <div class="col-12">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
            <label class="form-check-label" for="remember">Manter conectado</label>
        </div>
    </div>
    <div class="col-12"><div class="d-grid"><button type="submit" class="btn btn-primary">Entrar</button></div></div>
    @if (\App\Models\Setting::registrationOpen())
        <div class="col-12 text-center"><p class="mb-0">Ainda não tem conta? <a href="{{ route('register') }}">Cadastre-se</a></p></div>
    @endif
    <div class="col-12 text-center"><a href="{{ route('verify.index') }}"><i class="bx bx-badge-check"></i> Verificar um documento assinado</a></div>
</form>
@endsection
