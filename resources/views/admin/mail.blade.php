@extends('layouts.app')
@section('title', 'E-mail')
@section('content')
<p><a href="{{ route('admin.dashboard') }}">← Administração</a></p>
<h1>Configuração de e-mail (SMTP)</h1>
<p class="muted">Fonte atual: <strong>{{ $source }}</strong>. O que for salvo aqui passa a valer imediatamente e tem precedência sobre as variáveis de ambiente.</p>

<div class="card">
    <form method="post" action="{{ route('admin.mail.update') }}">
        @csrf @method('PUT')
        <div class="row">
            <div><label for="host">Servidor SMTP</label><input id="host" type="text" name="host" value="{{ old('host', $c['host']) }}" required placeholder="smtp.seuprovedor.com"></div>
            <div><label for="port">Porta</label><input id="port" type="number" name="port" value="{{ old('port', $c['port']) }}" required></div>
        </div>
        <label for="encryption">Segurança</label>
        <select id="encryption" name="encryption">
            @foreach (['tls' => 'STARTTLS (porta 587 — recomendado)', 'ssl' => 'SSL/TLS (porta 465)', 'none' => 'Sem criptografia (porta 25 — não recomendado)'] as $k => $label)
                <option value="{{ $k }}" @selected(old('encryption', $c['encryption']) === $k)>{{ $label }}</option>
            @endforeach
        </select>
        <div class="row">
            <div><label for="username">Usuário</label><input id="username" type="text" name="username" value="{{ old('username', $c['username']) }}" autocomplete="off"></div>
            <div><label for="password">Senha</label><input id="password" type="password" name="password" autocomplete="new-password" placeholder="{{ $hasPassword ? '•••••••• (deixe em branco para manter)' : '' }}"></div>
        </div>
        <div class="row">
            <div><label for="from_address">E-mail remetente</label><input id="from_address" type="email" name="from_address" value="{{ old('from_address', $c['from_address']) }}" required></div>
            <div><label for="from_name">Nome do remetente</label><input id="from_name" type="text" name="from_name" value="{{ old('from_name', $c['from_name']) }}" required></div>
        </div>
        <p class="muted">Use um remetente do seu domínio e confirme com o provedor que ele está autorizado (SPF/DKIM), senão os e-mails caem no spam. A senha é guardada cifrada.</p>
        <p><button class="btn">Salvar</button></p>
    </form>
</div>

<div class="card">
    <h2>Enviar e-mail de teste</h2>
    <form method="post" action="{{ route('admin.mail.test') }}" class="row" style="align-items:end">
        @csrf
        <div><label for="to">Enviar para</label><input id="to" type="email" name="to" value="{{ old('to', auth()->user()->email) }}" required></div>
        <div style="flex:0 0 auto"><button class="btn">Enviar teste</button></div>
    </form>
    @if ($errors->has('test'))<p class="muted">Dica: erro de autenticação = usuário/senha; "Connection could not be established" = host, porta ou segurança errados.</p>@endif
</div>

<div class="card">
    <form method="post" action="{{ route('admin.mail.reset') }}" onsubmit="return confirm('Remover a configuração salva no painel?')">
        @csrf @method('DELETE')
        <button class="btn ghost sm">Remover configuração do painel</button>
    </form>
</div>
@endsection
