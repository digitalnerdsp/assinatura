@extends('layouts.app')
@section('title', 'Verificar documento')
@section('width', 'narrow')
@section('content')
<div class="card">
    <h1>Verificar documento</h1>
    <p class="muted">Digite o código de verificação impresso no documento assinado.</p>
    <form method="post" action="{{ route('verify.lookup') }}">
        @csrf
        <label for="code">Código</label>
        <input id="code" type="text" name="code" required autofocus autocomplete="off">
        <p><button class="btn">Verificar</button></p>
    </form>
</div>
@endsection
