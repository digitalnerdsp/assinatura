@extends('layouts.app')
@section('title', $envelope->title)
@section('section', 'Envelopes')
@section('content')
@php($labels = [
    'envelope_created' => 'Envelope criado', 'envelope_sent' => 'Liberado para assinatura', 'document_viewed' => 'Documento visualizado',
    'otp_sent' => 'Código enviado', 'otp_failed' => 'Código incorreto', 'otp_verified' => 'Código confirmado',
    'document_signed' => 'Documento assinado', 'all_signed' => 'Todas as assinaturas coletadas', 'signer_refused' => 'Assinatura recusada',
    'selfie_captured' => 'Selfie registrada', 'document_photo_captured' => 'Foto do documento registrada', 'geolocation_captured' => 'Localização registrada',
    'link_regenerated' => 'Novo link gerado', 'envelope_sealed' => 'Documento selado', 'seal_failed' => 'Falha ao selar',
    'envelope_cancelled' => 'Envelope cancelado', 'envelope_expired' => 'Envelope expirado', 'reminder_sent' => 'Lembrete enviado',
])
@if ($envelope->isTrashed())
    <div class="alert alert-warning border-0 d-flex flex-wrap align-items-center gap-2">
        <span><i class="bx bx-trash"></i> <strong>Este documento está na lixeira</strong> e será apagado em 30 dias.</span>
        <form method="post" action="{{ route('envelopes.restore', $envelope) }}" class="ms-auto">@csrf<button class="btn btn-sm btn-outline-dark"><i class="bx bx-undo"></i> Restaurar</button></form>
    </div>
@endif
<div class="card radius-10">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h5 class="mb-0">{{ $envelope->title }}</h5>
            @include('partials.status-badge', ['status' => $envelope->status->value])
            <div class="ms-auto d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('envelopes.original', $envelope) }}"><i class="bx bx-download"></i> Original</a>
                @if ($envelope->final_path)<a class="btn btn-sm btn-success" href="{{ route('envelopes.final', $envelope) }}"><i class="bx bx-download"></i> PDF selado</a>@endif
                @if (in_array($envelope->status->value, ['draft', 'sent', 'in_progress']))
                    <form method="post" action="{{ route('envelopes.cancel', $envelope) }}" onsubmit="return confirm('Cancelar este envelope?')">@csrf<button class="btn btn-sm btn-outline-danger"><i class="bx bx-x"></i> Cancelar</button></form>
                @endif
                @if ($envelope->isTrashable() && ! $envelope->isTrashed())
                    <form method="post" action="{{ route('envelopes.trash', $envelope) }}" onsubmit="return confirm('Mover este documento para a lixeira? Ele será apagado em 30 dias.')">@csrf<button class="btn btn-sm btn-outline-secondary"><i class="bx bx-trash"></i> Mover para a lixeira</button></form>
                @endif
                @if ($envelope->isArchivable() && ! $envelope->isArchived())
                    <form method="post" action="{{ route('envelopes.archive', $envelope) }}">@csrf<button class="btn btn-sm btn-outline-secondary"><i class="bx bx-archive"></i> Arquivar</button></form>
                @elseif ($envelope->isArchived())
                    <form method="post" action="{{ route('envelopes.unarchive', $envelope) }}">@csrf<button class="btn btn-sm btn-outline-secondary"><i class="bx bx-archive-out"></i> Desarquivar</button></form>
                @endif
            </div>
        </div>

        @if ($envelope->status->value === 'sealing')
            <div class="alert alert-warning border-0 d-flex flex-wrap align-items-center gap-2">
                <i class="bx bx-time-five fs-4"></i>
                <div class="me-auto">Todos assinaram e o documento está aguardando a selagem. Se demorar, veja o motivo na trilha de auditoria abaixo.</div>
                <form method="post" action="{{ route('envelopes.reseal', $envelope) }}">@csrf<button class="btn btn-sm btn-warning"><i class="bx bx-refresh"></i> Tentar selar novamente</button></form>
            </div>
        @endif

        @if ($envelope->status->value === 'draft')
            <form method="post" action="{{ route('envelopes.send', $envelope) }}" class="border rounded p-3 mb-3 d-flex flex-wrap align-items-center gap-3">
                @csrf
                <div class="form-check form-switch mb-0 me-auto">
                    <input class="form-check-input" type="checkbox" id="skip_email" name="skip_email" value="1">
                    <label class="form-check-label" for="skip_email">Não enviar e-mail (vou copiar e enviar os links)</label>
                </div>
                <button class="btn btn-primary"><i class="bx bx-send"></i> Liberar para assinatura</button>
            </form>
        @endif

        <div class="row g-3 small text-secondary">
            <div class="col-md-4">Código de verificação<div class="mono text-body">{{ $envelope->verification_code }}</div>
                <a href="{{ route('verify.show', $envelope->verification_code) }}" target="_blank">página pública</a></div>
            <div class="col-md-4">SHA-256 do original<div class="mono text-body">{{ $envelope->document->sha256 }}</div></div>
            @if ($envelope->final_sha256)<div class="col-md-4">SHA-256 do selado<div class="mono text-body">{{ $envelope->final_sha256 }}</div></div>@endif
            <div class="col-md-4">Prazo<div class="text-body">{{ $envelope->expires_at ? $envelope->expires_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : $envelope->validity_days.' dias após o envio' }}</div></div>
            <div class="col-md-4">Lembrete<div class="text-body">{{ $envelope->reminder_every_days ? 'a cada '.$envelope->reminder_every_days.' dia(s)' : 'desativado' }}</div></div>
            <div class="col-md-4">Aviso por<div class="text-body">{{ collect($envelope->channels())->map(fn ($c) => ['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'manual' => 'Manual (links copiados)'][$c] ?? $c)->implode(', ') }}</div></div>
        </div>

        <form method="post" action="{{ route('envelopes.organize', $envelope) }}" class="row g-2 align-items-end mt-2">
            @csrf
            <div class="col-md-4"><label class="form-label small text-secondary mb-1">Pasta</label>
                <select class="form-select form-select-sm" name="folder_id"><option value="">Sem pasta</option>@foreach ($folders as $f)<option value="{{ $f->id }}" @selected($envelope->folder_id === $f->id)>{{ $f->name }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label small text-secondary mb-1">Etiquetas</label><br>
                @forelse ($allTags as $t)<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="t_{{ $t->id }}" name="tags[]" value="{{ $t->id }}" @checked($envelope->tags->contains('id', $t->id))><label class="form-check-label" for="t_{{ $t->id }}"><span class="badge bg-{{ $t->color }}">{{ $t->name }}</span></label></div>@empty<span class="small text-secondary">Sem etiquetas.</span>@endforelse</div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Salvar</button></div>
        </form>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-group me-1"></i> Signatários</h6></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Nome</th><th>Papel</th><th>E-mail</th><th>Status</th><th>Assinou em</th></tr></thead>
            <tbody>
            @foreach ($envelope->signers as $s)
                <tr>
                    <td>{{ $s->position }}</td><td>{{ $s->name }}</td><td>@if ($s->isSender())<span class="badge bg-primary">Remetente</span>@elseif ($s->isWitness())<span class="badge bg-secondary">Testemunha</span>@else<span class="text-secondary">Signatário</span>@endif</td><td>{{ $s->email }}</td>
                    <td>@include('partials.status-badge', ['status' => $s->status->value])@if($s->refused_reason)<div class="small text-secondary">{{ $s->refused_reason }}</div>@endif @if ($envelope->signing_order->value === 'sequential' && $envelope->status->isOpenForSigning() && ! in_array($s->status->value, ['signed', 'refused']))<div class="small text-secondary">{{ in_array($s->id, $activeIds) ? 'na vez de assinar' : 'aguardando a vez' }}</div>@endif</td>
                    <td>{{ $s->signed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@if ($envelope->status->isOpenForSigning())
<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-link me-1"></i> Links de assinatura</h6></div>
    <div class="card-body">
        <p class="text-secondary">Cada link é pessoal: quem o abre assina em nome daquele signatário. Envie cada um só para a pessoa certa.</p>
        @foreach ($envelope->signers as $s)
            @continue(in_array($s->status->value, ['signed', 'refused']))
            @php($url = $s->signUrl())
            <div class="border rounded p-3 mb-3">
                <strong>{{ $s->name }}</strong> <span class="text-secondary">· {{ $s->email }}</span>
                @if ($url)
                    <div class="input-group mt-2">
                        <input type="text" class="form-control" readonly value="{{ $url }}" onclick="this.select()" aria-label="Link de {{ $s->name }}">
                        <button type="button" class="btn btn-primary" data-copy="{{ $url }}"><i class="bx bx-copy"></i> Copiar link</button>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <form method="post" action="{{ route('envelopes.resend', [$envelope, $s]) }}">@csrf<button class="btn btn-sm btn-outline-secondary"><i class="bx bx-envelope"></i> Reenviar aviso</button></form>
                        <form method="post" action="{{ route('envelopes.regenerate', [$envelope, $s]) }}" onsubmit="return confirm('Gerar um novo link? O link atual deixará de funcionar.')">@csrf<button class="btn btn-sm btn-outline-secondary"><i class="bx bx-refresh"></i> Gerar novo link</button></form>
                    </div>
                @else
                    <p class="text-secondary mt-2 mb-2">Link indisponível para este envelope (enviado antes deste recurso).</p>
                    <form method="post" action="{{ route('envelopes.regenerate', [$envelope, $s]) }}">@csrf<button class="btn btn-sm btn-outline-primary">Gerar link</button></form>
                @endif
            </div>
        @endforeach
    </div>
</div>
@push('scripts')
<script>
document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', async function () {
        const url = b.dataset.copy, old = b.innerHTML;
        try { await navigator.clipboard.writeText(url); }
        catch (e) { const i = b.parentNode.querySelector('input'); i.select(); document.execCommand('copy'); }
        b.innerHTML = '<i class="bx bx-check"></i> Copiado'; setTimeout(() => b.innerHTML = old, 1800);
    });
});
</script>
@endpush
@endif

@if ($envelope->require_selfie || $envelope->require_document || $envelope->require_geolocation)
<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-id-card me-1"></i> Provas de identidade</h6></div>
    <div class="card-body">
        @if ($envelope->signers->every(fn ($s) => $s->attachments->isEmpty()) && ! $events->contains('type', 'geolocation_captured'))
            <p class="text-secondary mb-0">Aguardando o signatário concluir as verificações.</p>
        @endif
        @foreach ($envelope->signers as $s)
            @php($geo = $events->where('type', 'geolocation_captured')->firstWhere('signer_id', $s->id))
            @if ($s->attachments->isNotEmpty() || $geo)
                <div class="border rounded p-3 mb-3">
                    <strong>{{ $s->name }}</strong>
                    <div class="row g-3 mt-1">
                        @foreach ($s->attachments as $att)
                            <div class="col-md-4">
                                <div class="small text-secondary">{{ $att->kind === 'selfie' ? 'Selfie' : 'Documento' }} · {{ $att->source === 'camera' ? 'câmera' : 'upload' }}</div>
                                <a href="{{ route('envelopes.attachment', [$envelope, $att]) }}" target="_blank" rel="noopener">
                                    <img src="{{ route('envelopes.attachment', [$envelope, $att]) }}" alt="{{ $att->kind }}" class="img-fluid rounded" style="max-height:220px">
                                </a>
                                <div class="mono text-secondary">{{ $att->sha256 }}</div>
                            </div>
                        @endforeach
                    </div>
                    @if ($geo)
                        <p class="mt-3 mb-0 text-secondary"><i class="bx bx-map-pin"></i> {{ $geo->payload['lat'] }}, {{ $geo->payload['lng'] }} (±{{ $geo->payload['accuracy_m'] ?? '?' }} m) ·
                            <a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat={{ $geo->payload['lat'] }}&mlon={{ $geo->payload['lng'] }}#map=17/{{ $geo->payload['lat'] }}/{{ $geo->payload['lng'] }}">ver no mapa</a></p>
                    @endif
                </div>
            @endif
        @endforeach
    </div>
</div>
@endif

<div class="card radius-10">
    <div class="card-header d-flex align-items-center"><h6 class="mb-0"><i class="bx bx-history me-1"></i> Trilha de auditoria</h6>
        <span class="ms-auto badge {{ $intact ? 'bg-success' : 'bg-danger' }}">{{ $intact ? 'cadeia íntegra' : 'cadeia adulterada' }}</span></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Evento</th><th>Quando (BRT)</th><th>IP</th></tr></thead>
            <tbody>
            @foreach ($events as $ev)
                <tr>
                    <td>{{ $ev->sequence }}</td>
                    <td>{{ $labels[$ev->type] ?? $ev->type }}@if($ev->signer_id) <span class="text-secondary">· {{ $envelope->signers->firstWhere('id', $ev->signer_id)?->name }}</span>@endif
                        @if ($ev->type === 'seal_failed' && ! empty($ev->payload['error']))<div class="small text-danger">{{ $ev->payload['error'] }}</div>@endif</td>
                    <td class="text-nowrap">{{ $ev->occurred_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') }}</td>
                    <td class="mono">{{ $ev->ip ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
