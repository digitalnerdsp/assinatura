@include('mail.partials.brand-header')
<p>Olá, {{ $signer->name }}.</p>
@if ($reminder)
<p>Este é um lembrete: o documento <strong>{{ $signer->envelope->title }}</strong> ainda aguarda a sua assinatura @if ($signer->envelope->expires_at)(disponível até {{ $signer->envelope->expires_at->timezone('America/Sao_Paulo')->format('d/m/Y') }})@endif.</p>
@else
<p>Você recebeu o documento <strong>{{ $signer->envelope->title }}</strong> para assinar.</p>
@endif
@if ($signer->envelope->message)<p>{{ $signer->envelope->message }}</p>@endif
@if (! empty($brand['primary']))
<p><a href="{{ $url }}" style="display:inline-block;padding:12px 20px;background:{{ $brand['primary'] }};color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold">Abrir e assinar o documento</a></p>
@else
<p><a href="{{ $url }}">Abrir e assinar o documento</a></p>
@endif
<p>Se o botão não funcionar, copie este endereço no navegador:<br>{{ $url }}</p>
<p>Não encaminhe este e-mail: o link é pessoal e identifica você como signatário.</p>
