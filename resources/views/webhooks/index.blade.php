@extends('layouts.app')
@section('title', 'Webhooks')
@section('section', 'Integração')
@section('content')
@if (session('new_secret'))
    <div class="alert alert-success border-0">
        <strong><i class="bx bx-key"></i> Copie o segredo agora — ele não será exibido novamente.</strong>
        <div class="input-group mt-2">
            <input type="text" class="form-control mono" id="newsecret" readonly value="{{ session('new_secret') }}" onclick="this.select()">
            <button class="btn btn-success" type="button" id="copysecret"><i class="bx bx-copy"></i> Copiar</button>
        </div>
    </div>
    @push('scripts')<script>document.getElementById('copysecret').addEventListener('click', function () { navigator.clipboard.writeText(document.getElementById('newsecret').value); this.innerHTML = '<i class="bx bx-check"></i> Copiado'; });</script>@endpush
@endif

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0"><i class="bx bx-plus-circle me-1"></i> Novo webhook</h6></div>
    <div class="card-body">
        <form method="post" action="{{ route('webhooks.store') }}">
            @csrf
            <div class="mb-3">
                <label for="url" class="form-label">URL (https)</label>
                <input id="url" type="url" class="form-control" name="url" value="{{ old('url') }}" required placeholder="https://seu-sistema.com.br/webhooks/assinatura">
            </div>
            <label class="form-label">Eventos <span class="text-secondary">(nenhum marcado = todos)</span></label>
            <div class="mb-3">
                @foreach ($events as $key => $label)
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="ev_{{ $key }}" name="events[]" value="{{ $key }}">
                        <label class="form-check-label" for="ev_{{ $key }}"><code>{{ $key }}</code> <span class="text-secondary">{{ $label }}</span></label>
                    </div>
                @endforeach
            </div>
            <button class="btn btn-primary"><i class="bx bx-plus"></i> Criar webhook</button>
        </form>
    </div>
</div>

<div class="card radius-10">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>URL</th><th>Eventos</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($endpoints as $e)
                <tr>
                    <td class="mono">{{ $e->url }}</td>
                    <td>@if ($e->events) @foreach ($e->events as $ev)<span class="badge bg-light text-dark border me-1">{{ $ev }}</span>@endforeach @else <span class="text-secondary">todos</span> @endif</td>
                    <td>{!! $e->active ? '<span class="badge bg-success">ativo</span>' : '<span class="badge bg-secondary">desativado</span>' !!}</td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <form method="post" action="{{ route('webhooks.test', $e) }}">@csrf<button class="btn btn-sm btn-outline-primary">Testar</button></form>
                            <form method="post" action="{{ route('webhooks.toggle', $e) }}">@csrf<button class="btn btn-sm btn-outline-secondary">{{ $e->active ? 'Desativar' : 'Ativar' }}</button></form>
                            <form method="post" action="{{ route('webhooks.destroy', $e) }}" onsubmit="return confirm('Remover este webhook?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remover</button></form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-secondary p-4">Nenhum webhook configurado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0">Últimas entregas</h6></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Quando</th><th>Evento</th><th>URL</th><th>Status</th><th>Detalhe</th><th></th></tr></thead>
            <tbody>
            @forelse ($deliveries as $d)
                <tr>
                    <td>{{ $d->created_at->timezone('America/Sao_Paulo')->format('d/m H:i:s') }}</td>
                    <td><code>{{ $d->event }}</code></td>
                    <td class="mono small">{{ $d->endpoint?->url }}</td>
                    <td>@switch($d->status)
                        @case('delivered')<span class="badge bg-success">entregue</span>@break
                        @case('failed')<span class="badge bg-danger">falhou</span>@break
                        @default<span class="badge bg-warning text-dark">pendente</span>
                    @endswitch <span class="text-secondary small">{{ $d->attempts }}x</span></td>
                    <td class="small">{{ $d->response_code ? 'HTTP '.$d->response_code : '' }} {{ $d->error }}</td>
                    <td class="text-end">@if ($d->status === 'failed')<form method="post" action="{{ route('webhooks.redeliver', $d) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Reenviar</button></form>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-secondary p-4">Nenhuma entrega ainda.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header"><h6 class="mb-0">Como validar a assinatura</h6></div>
    <div class="card-body">
        <p>Cada chamada é um <code>POST</code> JSON com os cabeçalhos <code>X-Webhook-Id</code> (use para ignorar repetições), <code>X-Webhook-Event</code>, <code>X-Webhook-Timestamp</code> e <code>X-Webhook-Signature</code>.
        A assinatura é <code>sha256=</code> + HMAC-SHA256 de <code>timestamp + "." + corpo</code> usando o segredo do webhook. Responda com status 2xx; falhas são repetidas até 6 vezes.</p>
        <pre class="mono mb-0">// PHP
$esperado = 'sha256='.hash_hmac('sha256', $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'].'.'.file_get_contents('php://input'), $segredo);
if (! hash_equals($esperado, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])) { http_response_code(401); exit; }</pre>
    </div>
</div>
@endsection
