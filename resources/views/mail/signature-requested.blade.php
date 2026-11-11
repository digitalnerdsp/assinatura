<p>Olá, {{ $signer->name }}.</p>
@if ($reminder)
<p>Este é um lembrete: o documento <strong>{{ $signer->envelope->title }}</strong> ainda aguarda a sua assinatura @if ($signer->envelope->expires_at)(disponível até {{ $signer->envelope->expires_at->timezone('America/Sao_Paulo')->format('d/m/Y') }})@endif.</p>
@else
<p>Você recebeu o documento <strong>{{ $signer->envelope->title }}</strong> para assinar.</p>
@endif
@if ($signer->envelope->message)<p>{{ $signer->envelope->message }}</p>@endif
<p><a href="{{ $url }}">Abrir e assinar o documento</a></p>
<p>Se o botão não funcionar, copie este endereço no navegador:<br>{{ $url }}</p>
<p>Não encaminhe este e-mail: o link é pessoal e identifica você como signatário.</p>
