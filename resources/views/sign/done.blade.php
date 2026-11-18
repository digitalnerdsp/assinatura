@extends('layouts.public')
@section('title', 'Pronto')
@section('max', '640px')
@section('content')
@php
    $done = ! $refused && isset($envelope) && $envelope->status === \App\Enums\EnvelopeStatus::Completed && $envelope->files()->every(fn ($f) => $f->finalPath !== null);
@endphp
<div class="card radius-10 text-center">
    <div class="card-body p-5">
        @if ($refused)
            <div class="widgets-icons-2 rounded-circle bg-gradient-burning text-white mx-auto mb-3"><i class="bx bx-x"></i></div>
            <h4>Assinatura recusada</h4>
            <p class="text-secondary mb-0">Registramos sua recusa. O remetente será informado.</p>
        @else
            <div class="widgets-icons-2 rounded-circle bg-gradient-ohhappiness text-white mx-auto mb-3"><i class="bx bx-check"></i></div>
            <h4>Documento assinado</h4>
            <p class="text-secondary mb-0">Obrigado, {{ $signer->name }}. Sua assinatura foi registrada.</p>
            @if ($done)
                <p class="text-secondary mt-2 mb-0">O documento foi concluído por todos. Uma cópia assinada foi enviada para o seu e-mail.</p>
            @else
                <p class="text-secondary mt-2 mb-0">Quando todos assinarem, o documento será selado e você receberá a cópia assinada por e-mail. Esta página também passa a oferecer o download.</p>
            @endif
        @endif
    </div>
</div>

@if (! $refused && isset($token))
@php($files = $envelope->files())
<div class="card radius-10 mt-3">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="text-start">
                <div class="fw-semibold">{{ $files->count() > 1 ? 'Seus documentos assinados' : 'Seu documento assinado' }}</div>
                <div class="text-secondary small">{{ $done ? 'Guarde uma cópia: ela é a sua via do contrato.' : 'Disponível assim que todos assinarem.' }}</div>
            </div>
            @if ($done && $files->count() === 1)
                <div class="d-flex gap-2">
                    <a class="btn btn-outline-primary" href="{{ route('sign.copy.download', ['token' => $token, 'ver' => 1]) }}" target="_blank" rel="noopener">Ver documento</a>
                    <a class="btn btn-primary" href="{{ route('sign.copy.download', $token) }}">Baixar documento assinado</a>
                </div>
            @elseif ($done)
                <a class="btn btn-primary" href="{{ route('sign.copy.zip', $token) }}">Baixar todos</a>
            @else
                <span class="badge bg-light text-secondary">Aguardando os demais</span>
            @endif
        </div>
        @if ($files->count() > 1)
            <ul class="list-unstyled text-start mt-3 mb-0">
                @foreach ($files as $f)
                    <li class="d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 border-top">
                        <span>{{ $f->n }}. {{ $f->name }}</span>
                        @if ($done)
                            <span class="d-flex gap-2">
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('sign.copy.download', ['token' => $token, 'n' => $f->n, 'ver' => 1]) }}" target="_blank" rel="noopener">Ver</a>
                                <a class="btn btn-sm btn-primary" href="{{ route('sign.copy.download', ['token' => $token, 'n' => $f->n]) }}">Baixar</a>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endif
@endsection
