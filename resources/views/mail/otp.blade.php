@include('mail.partials.brand-header')
<p>Olá, {{ $signer->name }}.</p>
<p>Seu código para assinar <strong>{{ $signer->envelope->title }}</strong> é:</p>
<p style="font-size:28px;letter-spacing:4px"><strong>{{ $code }}</strong></p>
<p>Ele vale por 10 minutos. Se você não pediu este código, ignore este e-mail.</p>
