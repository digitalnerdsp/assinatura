Olá, {{ $member->name }}.

{{ $owner->name }} convidou você para acessar a conta da empresa {{ $owner->tenant->name }} no sistema de assinaturas.

Para aceitar e criar a sua senha, abra este endereço:
{{ $url }}

O link vale por {{ \App\Team\TeamService::INVITE_DAYS }} dias e só pode ser usado uma vez. Se você não esperava este convite, ignore este e-mail.
