@extends('layouts.auth', ['cover' => 'register-cover.svg'])
@section('title', 'Criar conta')
@section('subtitle', 'Crie a conta da sua empresa')
@section('content')
@unless ($open)
    <div class="alert alert-warning border-0">Os cadastros estão fechados no momento. Se você já tem conta, <a href="{{ route('login') }}">entre aqui</a>.</div>
@endunless
<form class="row g-3" method="post" action="{{ route('register') }}">
    @csrf
    <div class="col-12">
        <label for="company" class="form-label">Empresa</label>
        <input type="text" class="form-control" id="company" name="company" value="{{ old('company') }}" required autofocus>
    </div>
    <div class="col-12">
        <label for="name" class="form-label">Seu nome</label>
        <input type="text" class="form-control" id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
    </div>
    <div class="col-12">
        <label for="email" class="form-label">E-mail</label>
        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" required autocomplete="username">
    </div>
    <div class="col-12">
        <label for="password" class="form-label">Senha <span class="text-secondary">(mín. 10 caracteres)</span></label>
        <div class="input-group js-password">
            <input type="password" class="form-control border-end-0" id="password" name="password" required minlength="10" autocomplete="new-password">
            <a href="javascript:;" class="input-group-text bg-transparent"><i class="bx bx-hide"></i></a>
        </div>
    </div>
    <div class="col-12">
        <label for="password_confirmation" class="form-label">Confirmar senha</label>
        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
    </div>
    <div class="col-12"><div class="d-grid"><button type="submit" class="btn btn-primary" @disabled(! $open)>Criar conta</button></div></div>
    <div class="col-12 text-center"><p class="mb-0">Já tem conta? <a href="{{ route('login') }}">Entrar</a></p></div>
</form>
@endsection
