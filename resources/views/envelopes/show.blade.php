@extends('layouts.app')
@section('title', $envelope->title)
@section('content')
@php($labels = [
    'envelope_created' => 'Envelope criado', 'envelope_sent' => 'Convites enviados', 'document_viewed' => 'Documento visualizado',
    'otp_sent' => 'Código enviado', 'otp_failed' => 'Código incorreto', 'otp_verified' => 'Código confirmado',
    'document_signed' => 'Documento assinado', 'all_signed' => 'Todas as assinaturas coletadas', 'signer_refused' => 'Assinatura recusada',
    'envelope_sealed' => 'Documento selado', 'seal_failed' => 'Falha ao selar', 'envelope_cancelled' => 'Envelope cancelado', 'envelope_expired' => 'Envelope expirado',
])
<div class="row" style="align-items:center;margin-bottom:8px">
    <h1 style="margin:0">{{ $envelope->title }} <span class="badge {{ $envelope->status->value }}">{{ __('status.'.$envelope->status->value) }}</span></h1>
</div>

<div class="card">
    <div class="row">
        <a class="btn ghost sm" href="{{ route('envelopes.original', $envelope) }}">Baixar original</a>
        @if ($envelope->final_path)<a class="btn sm" href="{{ route('envelopes.final', $envelope) }}">Baixar PDF selado</a>@endif
        @if ($envelope->status->value === 'draft')
            <form method="post" action="{{ route('envelopes.send', $envelope) }}">@csrf<button class="btn sm">Enviar para assinatura</button></form>
        @endif
        @if (in_array($envelope->status->value, ['draft', 'sent', 'in_progress']))
            <form method="post" action="{{ route('envelopes.cancel', $envelope) }}" onsubmit="return confirm('Cancelar este envelope?')">@csrf<button class="btn danger sm">Cancelar</button></form>
        @endif
    </div>
    <p class="muted">Código de verificação: <span class="mono">{{ $envelope->verification_code }}</span> ·
        <a href="{{ route('verify.show', $envelope->verification_code) }}">página pública</a></p>
    <p class="muted">SHA-256 do original: <span class="mono">{{ $envelope->document->sha256 }}</span></p>
    @if ($envelope->final_sha256)<p class="muted">SHA-256 do selado: <span class="mono">{{ $envelope->final_sha256 }}</span></p>@endif
</div>

<div class="card">
    <h2>Signatários</h2>
    <table>
        <thead><tr><th>#</th><th>Nome</th><th>E-mail</th><th>Status</th><th>Assinou em</th></tr></thead>
        <tbody>
        @foreach ($envelope->signers as $s)
            <tr>
                <td>{{ $s->position }}</td><td>{{ $s->name }}</td><td>{{ $s->email }}</td>
                <td><span class="badge {{ $s->status->value }}">{{ __('status.'.$s->status->value) }}</span>@if($s->refused_reason)<div class="muted">{{ $s->refused_reason }}</div>@endif</td>
                <td>{{ $s->signed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Trilha de auditoria
        @if ($intact)<span class="badge completed">cadeia íntegra</span>@else<span class="badge refused">cadeia adulterada</span>@endif
    </h2>
    <table>
        <thead><tr><th>#</th><th>Evento</th><th>Quando (BRT)</th><th>IP</th></tr></thead>
        <tbody>
        @foreach ($events as $ev)
            <tr>
                <td>{{ $ev->sequence }}</td>
                <td>{{ $labels[$ev->type] ?? $ev->type }}@if($ev->signer_id) <span class="muted">· {{ $envelope->signers->firstWhere('id', $ev->signer_id)?->name }}</span>@endif</td>
                <td>{{ $ev->occurred_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') }}</td>
                <td class="mono">{{ $ev->ip ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
