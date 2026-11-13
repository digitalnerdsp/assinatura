<p>Olá, {{ $member->name }}.</p>
<p>{{ $actor->name }} convidou você para a <strong>equipe da plataforma</strong> como {{ $member->platform_role === 'super_admin' ? 'super administrador' : 'administrador' }}.</p>
<p><a href="{{ $url }}" style="display:inline-block;padding:12px 20px;background:#0b63c7;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold">Aceitar convite e criar senha</a></p>
<p>O link vale por {{ \App\Team\TeamService::INVITE_DAYS }} dias e só pode ser usado uma vez. Se você não esperava este convite, ignore este e-mail.</p>
