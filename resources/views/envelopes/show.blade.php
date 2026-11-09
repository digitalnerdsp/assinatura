@extends('layouts.app')
@section('title', $envelope->title)
@section('content')
@php($labels = [
    'envelope_created' => 'Envelope criado', 'envelope_sent' => 'Convites enviados', 'document_viewed' => 'Documento visualizado',
    'otp_sent' => 'Código enviado', 'otp_failed' => 'Código incorreto', 'otp_verified' => 'Código confirmado',
    'document_signed' => 'Documento assinado', 'all_signed' => 'Todas as assinaturas coletadas', 'signer_refused' => 'Assinatura recusada',
    'selfie_captured' => 'Selfie registrada', 'document_photo_captured' => 'Foto do documento registrada', 'geolocation_captured' => 'Localização registrada',
    'link_regenerated' => 'Novo link gerado',
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
            <form method="post" action="{{ route('envelopes.send', $envelope) }}" class="row" style="align-items:center">
                @csrf
                <label style="font-weight:400;margin:0;flex:1 1 260px"><input type="checkbox" name="skip_email" value="1"> Não enviar e-mail (vou copiar e enviar os links)</label>
                <button class="btn sm" style="flex:0 0 auto">Liberar para assinatura</button>
            </form>
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

@if ($envelope->status->isOpenForSigning())
<div class="card">
    <h2>Links de assinatura</h2>
    <p class="muted">Cada link é pessoal: quem o abre assina em nome daquele signatário. Envie cada um só para a pessoa certa.</p>
    @foreach ($envelope->signers as $s)
        @continue(in_array($s->status->value, ['signed', 'refused']))
        @php($url = $s->signUrl())
        <div class="signer">
            <strong>{{ $s->name }}</strong> <span class="muted">· {{ $s->email }}</span>
            @if ($url)
                <div class="row" style="margin-top:8px;align-items:center">
                    <input type="text" readonly value="{{ $url }}" onclick="this.select()" style="flex:1 1 320px" aria-label="Link de {{ $s->name }}">
                    <button type="button" class="btn sm" style="flex:0 0 auto" data-copy="{{ $url }}">Copiar link</button>
                    <form method="post" action="{{ route('envelopes.resend', [$envelope, $s]) }}" style="flex:0 0 auto">@csrf<button class="btn ghost sm">Reenviar e-mail</button></form>
                    <form method="post" action="{{ route('envelopes.regenerate', [$envelope, $s]) }}" style="flex:0 0 auto" onsubmit="return confirm('Gerar um novo link? O link atual deixará de funcionar.')">@csrf<button class="btn ghost sm">Gerar novo link</button></form>
                </div>
            @else
                <p class="muted">Link indisponível para este envelope (enviado antes deste recurso).</p>
                <form method="post" action="{{ route('envelopes.regenerate', [$envelope, $s]) }}">@csrf<button class="btn ghost sm">Gerar link</button></form>
            @endif
        </div>
    @endforeach
</div>
<script>
document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', async function () {
        const url = b.dataset.copy, old = b.textContent;
        try { await navigator.clipboard.writeText(url); }
        catch (e) { const i = b.parentNode.querySelector('input'); i.select(); document.execCommand('copy'); }
        b.textContent = 'Copiado ✓'; setTimeout(() => b.textContent = old, 1800);
    });
});
</script>
@endif

@if ($envelope->require_selfie || $envelope->require_document || $envelope->require_geolocation)
<div class="card">
    <h2>Provas de identidade</h2>
    @foreach ($envelope->signers as $s)
        @php($geo = $events->where('type', 'geolocation_captured')->firstWhere('signer_id', $s->id))
        @if ($s->attachments->isNotEmpty() || $geo)
        <div class="signer">
            <strong>{{ $s->name }}</strong>
            <div class="row" style="margin-top:8px">
                @foreach ($s->attachments as $att)
                    <div>
                        <div class="muted">{{ $att->kind === 'selfie' ? 'Selfie' : 'Documento' }} · {{ $att->source === 'camera' ? 'câmera' : 'upload' }}</div>
                        <a href="{{ route('envelopes.attachment', [$envelope, $att]) }}" target="_blank" rel="noopener">
                            <img src="{{ route('envelopes.attachment', [$envelope, $att]) }}" alt="{{ $att->kind }}" style="max-width:100%;max-height:220px;border-radius:8px">
                        </a>
                        <div class="mono">{{ $att->sha256 }}</div>
                    </div>
                @endforeach
            </div>
            @if ($geo)
                <p class="muted">Localização: {{ $geo->payload['lat'] }}, {{ $geo->payload['lng'] }} (±{{ $geo->payload['accuracy_m'] ?? '?' }} m)
                    · <a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat={{ $geo->payload['lat'] }}&mlon={{ $geo->payload['lng'] }}#map=17/{{ $geo->payload['lat'] }}/{{ $geo->payload['lng'] }}">ver no mapa</a></p>
            @endif
        </div>
        @endif
    @endforeach
</div>
@endif

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
