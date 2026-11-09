@extends('layouts.public')
@section('title', 'Pronto')
@section('max', '560px')
@section('content')
<div class="card radius-10 text-center">
    <div class="card-body p-5">
        @if ($refused)
            <div class="widgets-icons-2 rounded-circle bg-gradient-burning text-white mx-auto mb-3"><i class="bx bx-x"></i></div>
            <h4>Assinatura recusada</h4>
            <p class="text-secondary mb-0">Registramos sua recusa. O remetente será informado.</p>
        @else
            <div class="widgets-icons-2 rounded-circle bg-gradient-ohhappiness text-white mx-auto mb-3"><i class="bx bx-check"></i></div>
            <h4>Documento assinado</h4>
            <p class="text-secondary mb-0">Obrigado, {{ $signer->name }}. Sua assinatura foi registrada. Quando todos assinarem, o documento será selado e o remetente será notificado.</p>
        @endif
    </div>
</div>
@endsection
