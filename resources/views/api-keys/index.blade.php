@extends('layouts.app')
@section('title', 'API')
@section('section', 'Integração')
@section('content')
@if (session('new_key'))
    <div class="alert alert-success border-0">
        <strong><i class="bx bx-key"></i> Copie a chave agora — ela não será exibida novamente.</strong>
        <div class="input-group mt-2">
            <input type="text" class="form-control mono" id="newkey" readonly value="{{ session('new_key') }}" onclick="this.select()">
            <button class="btn btn-success" type="button" id="copykey"><i class="bx bx-copy"></i> Copiar</button>
        </div>
    </div>
    @push('scripts')<script>document.getElementById('copykey').addEventListener('click', function () { navigator.clipboard.writeText(document.getElementById('newkey').value); this.innerHTML = '<i class="bx bx-check"></i> Copiado'; });</script>@endpush
@endif

<div class="card radius-10">
    <div class="card-body">
        <form method="post" action="{{ route('api-keys.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md"><label for="name" class="form-label">Nome da chave</label><input id="name" type="text" class="form-control" name="name" required placeholder="ex.: ERP produção"></div>
            <div class="col-md-auto"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="read_only" name="read_only" value="1"><label class="form-check-label" for="read_only">Somente leitura</label></div></div>
            <div class="col-md-auto"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="can_sign_as_company" name="can_sign_as_company" value="1"><label class="form-check-label" for="can_sign_as_company">Pode assinar em nome da empresa</label></div><div class="form-text">Use só em chaves dos seus próprios sistemas: com ela, a API assina contratos pela empresa.</div></div>
            <div class="col-md-auto"><button class="btn btn-primary"><i class="bx bx-plus"></i> Gerar chave</button></div>
        </form>
    </div>
</div>

<div class="card radius-10">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Nome</th><th>Prefixo</th><th>Último uso</th><th></th></tr></thead>
            <tbody>
            @forelse ($keys as $k)
                <tr>
                    <td>{{ $k->name }}</td><td class="mono">{{ $k->prefix }}…@if ($k->read_only) <span class="badge bg-info text-dark">leitura</span>@endif @if ($k->can_sign_as_company) <span class="badge bg-warning text-dark">Assina pela empresa</span>@endif</td>
                    <td>{{ $k->last_used_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'nunca' }}</td>
                    <td class="text-end">@if ($k->revoked_at)<span class="badge bg-danger">revogada</span>@else
                        <form method="post" action="{{ route('api-keys.destroy', $k) }}" onsubmit="return confirm('Revogar esta chave?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Revogar</button></form>@endif</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-secondary p-4">Nenhuma chave ainda.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card radius-10">
    <div class="card-header d-flex align-items-center"><h6 class="mb-0">Documentação</h6>
        <a class="btn btn-sm btn-outline-primary ms-auto" href="{{ route('api.docs') }}" target="_blank"><i class="bx bx-book-open"></i> Referência completa</a></div>
    <div class="card-body">
        <p class="text-secondary">Base: <code>{{ url('/api/v1') }}</code> · autenticação <code>Authorization: Bearer SUA_CHAVE</code> · 120 requisições por minuto por chave. Especificação OpenAPI: <a href="{{ url('/api/v1/openapi.json') }}" target="_blank">openapi.json</a> (importe no Postman, Insomnia ou gere um cliente).</p>
        <p class="mb-1"><strong>Criar e enviar (JSON, PDF em base64):</strong></p>
<pre class="mono">curl -X POST {{ url('/api/v1/envelopes') }} \n  -H "Authorization: Bearer SUA_CHAVE" -H "Content-Type: application/json" \n  -H "Idempotency-Key: pedido-1234" \n  -d '{"title":"Contrato","external_id":"pedido-1234","document_base64":"JVBERi0x...",
       "signers":[{"name":"Ana","email":"ana@exemplo.com","phone":"(11) 99999-8888"}],
       "channels":["email","whatsapp"],"require_selfie":true,"send":true}'</pre>
        <p class="mb-1"><strong>Ou com arquivo (multipart):</strong></p>
<pre class="mono">curl -X POST {{ url('/api/v1/envelopes') }} \n  -H "Authorization: Bearer SUA_CHAVE" \n  -F title="Contrato" -F document=@contrato.pdf \n  -F "signers[0][name]=Ana" -F "signers[0][email]=ana@exemplo.com" -F send=true</pre>
        <p class="mb-1"><strong>Consultar pelo seu identificador e baixar o selado:</strong></p>
<pre class="mono mb-0">curl -H "Authorization: Bearer SUA_CHAVE" {{ url('/api/v1/envelopes/pedido-1234') }}
curl -H "Authorization: Bearer SUA_CHAVE" -o assinado.pdf {{ url('/api/v1/envelopes/pedido-1234/download') }}</pre>
    </div>
</div>
@endsection
