@extends('layouts.app')
@section('title', 'Pronto')
@section('width', 'narrow')
@section('content')
<div class="card">
    @if ($refused)
        <h1>Assinatura recusada</h1>
        <p>Registramos sua recusa. O remetente será informado.</p>
    @else
        <h1>Documento assinado</h1>
        <p>Obrigado, {{ $signer->name }}. Sua assinatura foi registrada. Quando todos assinarem, o documento será selado e o remetente será notificado.</p>
    @endif
</div>
@endsection
