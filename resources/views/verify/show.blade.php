@extends('layouts.app')
@section('title', 'Verificação '.$envelope->verification_code)
@section('width', 'sign')
@section('content')
<h1>Verificação de documento</h1>

<div class="card">
    <p><strong>{{ $envelope->title }}</strong> · {{ $envelope->tenant->name }}</p>
    <p>Status: <span class="badge {{ $envelope->status->value }}">{{ __('status.'.$envelope->status->value) }}</span>
        @if ($intact)<span class="badge completed">trilha de auditoria íntegra</span>@else<span class="badge refused">trilha de auditoria inconsistente</span>@endif
    </p>
    <p class="muted">Código: <span class="mono">{{ $envelope->verification_code }}</span></p>
    <p class="muted">SHA-256 do original:<br><span class="mono">{{ $envelope->document->sha256 }}</span></p>
    @if ($envelope->final_sha256)
        <p class="muted">SHA-256 do documento selado:<br><span class="mono">{{ $envelope->final_sha256 }}</span></p>
        <p class="muted">Selado em {{ $envelope->sealed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') }} (BRT)
            · certificado <span class="mono">{{ $envelope->sealed_cert_serial }}</span></p>
    @endif
</div>

<div class="card">
    <h2>Signatários</h2>
    <table>
        <thead><tr><th>Nome</th><th>Status</th><th>Assinou em (BRT)</th></tr></thead>
        <tbody>
        @foreach ($envelope->signers as $s)
            <tr>
                <td>{{ $s->name }}</td>
                <td><span class="badge {{ $s->status->value }}">{{ __('status.'.$s->status->value) }}</span></td>
                <td>{{ $s->signed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Conferir um arquivo</h2>
    <p class="muted">Envie o PDF que você recebeu. Calculamos o hash no servidor e comparamos; o arquivo não é guardado.</p>
    @if ($match === 'final')<div class="ok">O arquivo é idêntico ao documento selado.</div>
    @elseif ($match === 'original')<div class="ok">O arquivo é idêntico ao documento original enviado para assinatura.</div>
    @elseif ($match === 'none')<div class="err">O arquivo NÃO corresponde a este envelope. Hash calculado: <span class="mono">{{ $uploadedHash }}</span></div>@endif
    <form method="post" action="{{ route('verify.check', $envelope->verification_code) }}" enctype="multipart/form-data">
        @csrf
        <input type="file" name="file" accept="application/pdf" required>
        <p><button class="btn">Conferir</button></p>
    </form>
</div>
@endsection
