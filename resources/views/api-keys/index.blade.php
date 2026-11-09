@extends('layouts.app')
@section('title', 'API')
@section('content')
<h1>Chaves de API</h1>
@if (session('new_key'))
    <div class="ok">
        <strong>Copie a chave agora — ela não será exibida novamente.</strong>
        <div class="mono" style="margin-top:6px">{{ session('new_key') }}</div>
    </div>
@endif
<div class="card">
    <form method="post" action="{{ route('api-keys.store') }}" class="row" style="align-items:end">
        @csrf
        <div><label for="name">Nome da chave</label><input id="name" type="text" name="name" required placeholder="ex.: ERP produção"></div>
        <div style="flex:0 0 auto"><button class="btn">Gerar chave</button></div>
    </form>
</div>
<div class="card">
    <table>
        <thead><tr><th>Nome</th><th>Prefixo</th><th>Último uso</th><th></th></tr></thead>
        <tbody>
        @forelse ($keys as $k)
            <tr>
                <td>{{ $k->name }}</td><td class="mono">{{ $k->prefix }}…</td>
                <td>{{ $k->last_used_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'nunca' }}</td>
                <td>@if ($k->revoked_at)<span class="badge refused">revogada</span>@else
                    <form method="post" action="{{ route('api-keys.destroy', $k) }}" onsubmit="return confirm('Revogar esta chave?')">@csrf @method('DELETE')<button class="btn danger sm">Revogar</button></form>@endif</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Nenhuma chave ainda.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="card">
    <h2>Exemplo</h2>
    <pre class="mono">curl -X POST {{ url('/api/v1/envelopes') }} \
  -H "Authorization: Bearer SUA_CHAVE" \
  -F title="Contrato" -F document=@contrato.pdf \
  -F "signers[0][name]=Ana" -F "signers[0][email]=ana@exemplo.com" \
  -F send=true</pre>
</div>
@endsection
