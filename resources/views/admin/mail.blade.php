@extends('layouts.app')
@section('title', 'E-mail (SMTP)')
@section('section', 'Plataforma')
@section('content')
<div class="alert alert-info border-0">Fonte atual: <strong>{{ $source }}</strong>. O que for salvo aqui passa a valer imediatamente e tem precedência sobre as variáveis de ambiente.</div>

<div class="row">
    <div class="col-12 col-xl-7">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-envelope me-1"></i> Servidor SMTP</h6></div>
            <div class="card-body">
                <form method="post" action="{{ route('admin.mail.update') }}" class="row g-3">
                    @csrf @method('PUT')
                    <div class="col-md-8"><label for="host" class="form-label">Servidor SMTP</label><input id="host" type="text" class="form-control" name="host" value="{{ old('host', $c['host']) }}" required placeholder="smtp.seuprovedor.com"></div>
                    <div class="col-md-4"><label for="port" class="form-label">Porta</label><input id="port" type="number" class="form-control" name="port" value="{{ old('port', $c['port']) }}" required></div>
                    <div class="col-12"><label for="encryption" class="form-label">Segurança</label>
                        <select id="encryption" class="form-select" name="encryption">
                            @foreach (['tls' => 'STARTTLS (porta 587 — recomendado)', 'ssl' => 'SSL/TLS (porta 465)', 'none' => 'Sem criptografia (porta 25 — não recomendado)'] as $k => $label)
                                <option value="{{ $k }}" @selected(old('encryption', $c['encryption']) === $k)>{{ $label }}</option>
                            @endforeach
                        </select></div>
                    <div class="col-md-6"><label for="username" class="form-label">Usuário</label><input id="username" type="text" class="form-control" name="username" value="{{ old('username', $c['username']) }}" autocomplete="off"></div>
                    <div class="col-md-6"><label for="password" class="form-label">Senha</label><input id="password" type="password" class="form-control" name="password" autocomplete="new-password" placeholder="{{ $hasPassword ? '•••••••• (em branco mantém)' : '' }}"></div>
                    <div class="col-md-6"><label for="from_address" class="form-label">E-mail remetente</label><input id="from_address" type="email" class="form-control" name="from_address" value="{{ old('from_address', $c['from_address']) }}" required></div>
                    <div class="col-md-6"><label for="from_name" class="form-label">Nome do remetente</label><input id="from_name" type="text" class="form-control" name="from_name" value="{{ old('from_name', $c['from_name']) }}" required></div>
                    <div class="col-12"><p class="text-secondary mb-0">Use um remetente do seu domínio e confirme com o provedor que ele está autorizado (SPF/DKIM), senão os e-mails caem no spam. A senha é guardada cifrada.</p></div>
                    <div class="col-12"><button class="btn btn-primary">Salvar</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="card radius-10">
            <div class="card-header"><h6 class="mb-0"><i class="bx bx-paper-plane me-1"></i> Enviar e-mail de teste</h6></div>
            <div class="card-body">
                <form method="post" action="{{ route('admin.mail.test') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col"><label for="to" class="form-label">Enviar para</label><input id="to" type="email" class="form-control" name="to" value="{{ old('to', auth()->user()->email) }}" required></div>
                    <div class="col-auto"><button class="btn btn-primary">Enviar teste</button></div>
                </form>
                @if ($errors->has('test'))<p class="text-secondary mt-3 mb-0">Dica: erro de autenticação = usuário/senha; "Connection could not be established" = host, porta ou segurança errados.</p>@endif
            </div>
        </div>
        <div class="card radius-10">
            <div class="card-body">
                <form method="post" action="{{ route('admin.mail.reset') }}" onsubmit="return confirm('Remover a configuração salva no painel?')">@csrf @method('DELETE')
                    <button class="btn btn-sm btn-outline-secondary">Remover configuração do painel</button></form>
            </div>
        </div>
    </div>
</div>
@endsection
