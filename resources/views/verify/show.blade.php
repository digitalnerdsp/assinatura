@extends('layouts.public')
@section('title', 'Verificação '.$envelope->verification_code)
@section('content')
<div class="card radius-10">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h4 class="mb-0">{{ $envelope->title }}</h4>
            @include('partials.status-badge', ['status' => $envelope->status->value])
            <span class="badge {{ $intact ? 'bg-success' : 'bg-danger' }}">{{ $intact ? 'trilha de auditoria íntegra' : 'trilha de auditoria inconsistente' }}</span>
        </div>
        <p class="text-secondary">Emitido por <strong>{{ $envelope->tenant->name }}</strong> · código <span class="mono">{{ $envelope->verification_code }}</span></p>
        <div class="small text-secondary">SHA-256 do original<div class="mono text-body">{{ $envelope->document->sha256 }}</div></div>
        @if ($envelope->final_sha256)
            <div class="small text-secondary mt-2">SHA-256 do documento selado<div class="mono text-body">{{ $envelope->final_sha256 }}</div></div>
            <p class="small text-secondary mt-2 mb-0">Selado em {{ $envelope->sealed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') }} (BRT) · certificado <span class="mono">{{ $envelope->sealed_cert_serial }}</span></p>
        @endif
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-group me-1"></i> Signatários</h6></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Nome</th><th>Status</th><th>Assinou em (BRT)</th></tr></thead>
            <tbody>
            @foreach ($envelope->signers as $s)
                <tr><td>{{ $s->name }}</td><td>@include('partials.status-badge', ['status' => $s->status->value])</td><td>{{ $s->signed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') ?? '—' }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-search-alt me-1"></i> Conferir um arquivo</h6></div>
    <div class="card-body">
        <p class="text-secondary">Envie o PDF que você recebeu. Calculamos o hash no servidor e comparamos; o arquivo não é guardado.</p>
        @if ($match === 'final')<div class="alert alert-success border-0">O arquivo é idêntico ao documento selado.</div>
        @elseif ($match === 'original')<div class="alert alert-success border-0">O arquivo é idêntico ao documento original enviado para assinatura.</div>
        @elseif ($match === 'none')<div class="alert alert-danger border-0">O arquivo NÃO corresponde a este envelope. Hash calculado: <span class="mono">{{ $uploadedHash }}</span></div>@endif
        <form method="post" action="{{ route('verify.check', $envelope->verification_code) }}" enctype="multipart/form-data" class="row g-2">
            @csrf
            <div class="col-md"><input type="file" class="form-control" name="file" accept="application/pdf" required></div>
            <div class="col-md-auto"><button class="btn btn-primary">Conferir</button></div>
        </form>
    </div>
</div>
@endsection
