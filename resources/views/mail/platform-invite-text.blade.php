Olá, {{ $member->name }}.

{{ $actor->name }} convidou você para a equipe da plataforma como {{ $member->platform_role === 'super_admin' ? 'super administrador' : 'administrador' }}.

Para aceitar e criar a sua senha, abra este endereço:
{{ $url }}

O link vale por {{ \App\Team\TeamService::INVITE_DAYS }} dias e só pode ser usado uma vez. Se você não esperava este convite, ignore este e-mail.
