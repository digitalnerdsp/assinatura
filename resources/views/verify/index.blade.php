@extends('layouts.public')
@section('title', 'Verificar documento')
@section('max', '560px')
@section('content')
<div class="card radius-10">
    <div class="card-body p-4">
        <div class="text-center mb-3"><div class="widgets-icons-2 rounded-circle bg-gradient-blues text-white mx-auto"><i class="bx bx-badge-check"></i></div></div>
        <h4 class="text-center">Verificar documento</h4>
        <p class="text-secondary text-center">Digite o código de verificação impresso no documento assinado.</p>
        <form method="post" action="{{ route('verify.lookup') }}">
            @csrf
            <label for="code" class="form-label">Código</label>
            <input id="code" type="text" class="form-control form-control-lg mb-3" name="code" required autofocus autocomplete="off">
            <button class="btn btn-primary w-100">Verificar</button>
        </form>
    </div>
</div>
@endsection
