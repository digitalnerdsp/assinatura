@extends('layouts.app')
@section('title', 'Novo envelope')
@section('content')
<h1>Novo envelope</h1>
<form method="post" action="{{ route('envelopes.store') }}" enctype="multipart/form-data" id="form">
    @csrf
    <div class="card">
        <h2>Documento</h2>
        <label for="title">Título</label>
        <input id="title" type="text" name="title" value="{{ old('title') }}" required>
        <label for="document">PDF (até 20 MB)</label>
        <input id="document" type="file" name="document" accept="application/pdf" required>
        <label for="message">Mensagem aos signatários (opcional)</label>
        <textarea id="message" name="message" rows="3">{{ old('message') }}</textarea>
        <div class="row">
            <div>
                <label for="signing_order">Ordem de assinatura</label>
                <select id="signing_order" name="signing_order">
                    <option value="parallel" @selected(old('signing_order') === 'parallel')>Todos ao mesmo tempo</option>
                    <option value="sequential" @selected(old('signing_order') === 'sequential')>Um após o outro (na ordem abaixo)</option>
                </select>
            </div>
        </div>
        <label>Verificação de identidade do signatário</label>
        <p class="muted" style="margin-top:0">Sem nenhuma opção marcada, vale só o link enviado por e-mail (assinatura simples). Com qualquer verificação, a assinatura é registrada como avançada.</p>
        <label style="font-weight:400"><input type="checkbox" name="require_otp" value="1" @checked(old('require_otp'))> Código de confirmação por e-mail</label>
        <label style="font-weight:400"><input type="checkbox" name="require_selfie" value="1" @checked(old('require_selfie'))> Selfie (câmera)</label>
        <label style="font-weight:400"><input type="checkbox" name="require_document" value="1" @checked(old('require_document'))> Foto do documento (RG/CNH)</label>
        <label style="font-weight:400"><input type="checkbox" name="require_geolocation" value="1" @checked(old('require_geolocation'))> Localização (GPS)</label>
    </div>

    <div class="card">
        <h2>Signatários</h2>
        <div id="signers">
            @php($rows = old('signers', [['name' => '', 'email' => '']]))
            @foreach ($rows as $i => $s)
                <div class="signer">
                    <div class="row">
                        <div><label>Nome</label><input type="text" name="signers[{{ $i }}][name]" value="{{ $s['name'] ?? '' }}" required></div>
                        <div><label>E-mail</label><input type="email" name="signers[{{ $i }}][email]" value="{{ $s['email'] ?? '' }}" required></div>
                    </div>
                    <div class="row">
                        <div><label>CPF (opcional)</label><input type="text" name="signers[{{ $i }}][cpf]" value="{{ $s['cpf'] ?? '' }}"></div>
                        <div><label>Telefone (opcional)</label><input type="text" name="signers[{{ $i }}][phone]" value="{{ $s['phone'] ?? '' }}"></div>
                    </div>
                </div>
            @endforeach
        </div>
        <button type="button" class="btn ghost sm" id="add">+ Adicionar signatário</button>
    </div>

    <button class="btn">Criar envelope</button>
</form>
<script>
(function () {
    const wrap = document.getElementById('signers');
    document.getElementById('add').addEventListener('click', function () {
        const i = wrap.children.length;
        const div = document.createElement('div');
        div.className = 'signer';
        div.innerHTML =
            '<div class="row"><div><label>Nome</label><input type="text" name="signers[' + i + '][name]" required></div>' +
            '<div><label>E-mail</label><input type="email" name="signers[' + i + '][email]" required></div></div>' +
            '<div class="row"><div><label>CPF (opcional)</label><input type="text" name="signers[' + i + '][cpf]"></div>' +
            '<div><label>Telefone (opcional)</label><input type="text" name="signers[' + i + '][phone]"></div></div>';
        wrap.appendChild(div);
    });
})();
</script>
@endsection
