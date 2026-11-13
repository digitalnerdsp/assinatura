<p>Olá, {{ $member->name }}.</p>
<p>{{ $owner->name }} convidou você para acessar a conta da empresa <strong>{{ $owner->tenant->name }}</strong> no sistema de assinaturas.</p>
<p><a href="{{ $url }}" style="display:inline-block;padding:12px 20px;background:#0b63c7;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold">Aceitar convite e criar senha</a></p>
<p>O link vale por {{ \App\Team\TeamService::INVITE_DAYS }} dias e só pode ser usado uma vez. Se você não esperava este convite, ignore este e-mail.</p>
