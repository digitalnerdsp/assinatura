<p>Olá, {{ $signer->name }}.</p>
<p>Você recebeu o documento <strong>{{ $signer->envelope->title }}</strong> para assinar.</p>
@if ($signer->envelope->message)<p>{{ $signer->envelope->message }}</p>@endif
<p><a href="{{ $url }}">Abrir e assinar o documento</a></p>
<p>Se o botão não funcionar, copie este endereço no navegador:<br>{{ $url }}</p>
<p>Não encaminhe este e-mail: o link é pessoal e identifica você como signatário.</p>
