@extends('layouts.auth')
@section('title', 'Aceitar convite')
@section('subtitle', 'Crie sua senha para entrar em '.$invitee->tenant->name)
@section('content')
<form class="row g-3" method="post" action="{{ route('invite.accept', $token) }}">
    @csrf
    <div class="col-12">
        <label class="form-label" for="i-email">E-mail</label>
        <input type="email" class="form-control" id="i-email" value="{{ $invitee->email }}" readonly autocomplete="username">
    </div>
    <div class="col-12">
        <label class="form-label" for="password">Senha (mínimo 10 caracteres)</label>
        <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" minlength="10" required autocomplete="new-password" autofocus>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <label class="form-label" for="password_confirmation">Repita a senha</label>
        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" minlength="10" required autocomplete="new-password">
    </div>
    <div class="col-12"><div class="d-grid"><button type="submit" class="btn btn-primary">Criar senha e entrar</button></div></div>
</form>
@endsection
