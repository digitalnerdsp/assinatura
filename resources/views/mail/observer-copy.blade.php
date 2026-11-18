@include('mail.partials.brand-header')
<p>Olá, {{ $observer->name }}.</p>
<p>O documento <strong>{{ $observer->envelope->title }}</strong>, do qual você é observador, foi assinado por todos e está concluído. A cópia assinada segue em anexo (PDF).</p>
@if ($partial)
<p><strong>Alguns arquivos não couberam neste e-mail</strong> (limite de tamanho). Baixe todos pelo link abaixo.</p>
@endif
<p>Para baixar todos os arquivos assinados de uma vez (zip), por 30 dias: <a href="{{ $zipUrl }}">baixar documentos assinados</a>.</p>
<p>Para conferir a autenticidade do documento, use o código <strong>{{ $observer->envelope->verification_code }}</strong> em:<br><a href="{{ $verifyUrl }}">{{ $verifyUrl }}</a></p>
<p>Você recebeu este e-mail só como cópia: não é preciso assinar nem responder.</p>
