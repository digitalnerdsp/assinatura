@include('mail.partials.brand-header')
<p>Olá, {{ $observer->name }}.</p>
<p>O documento <strong>{{ $observer->envelope->title }}</strong>, do qual você é observador, foi assinado por todos e está concluído. A cópia assinada segue em anexo (PDF).</p>
<p>Para conferir a autenticidade do documento, use o código <strong>{{ $observer->envelope->verification_code }}</strong> em:<br><a href="{{ $verifyUrl }}">{{ $verifyUrl }}</a></p>
<p>Você recebeu este e-mail só como cópia: não é preciso assinar nem responder.</p>
