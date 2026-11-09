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
                    <td>{{ $k->name }}</td><td class="mono">{{ $k->prefix }}…</td>
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
    <div class="card-header"><h6 class="mb-0">Exemplo</h6></div>
    <div class="card-body"><pre class="mono mb-0">curl -X POST {{ url('/api/v1/envelopes') }} \
  -H "Authorization: Bearer SUA_CHAVE" \
  -F title="Contrato" -F document=@contrato.pdf \
  -F "signers[0][name]=Ana" -F "signers[0][email]=ana@exemplo.com" \
  -F require_selfie=1 -F send=true</pre></div>
</div>
@endsection
