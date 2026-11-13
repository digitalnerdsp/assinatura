<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Convite para acessar {{ $owner->tenant->name }}</title></head>
<body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1a1a1a">
<p>Olá, {{ $member->name }}.</p>
<p>{{ $owner->name }} convidou você para acessar a conta da empresa <strong>{{ $owner->tenant->name }}</strong> no sistema de assinaturas.</p>
<p><a href="{{ $url }}" style="display:inline-block;padding:12px 20px;background:#0b63c7;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold">Aceitar convite e criar senha</a></p>
<p>Se o botão não funcionar, copie este endereço no navegador:<br><a href="{{ $url }}">{{ $url }}</a></p>
<p>O link vale por {{ \App\Team\TeamService::INVITE_DAYS }} dias e só pode ser usado uma vez. Se você não esperava este convite, ignore este e-mail.</p>
</body>
</html>
