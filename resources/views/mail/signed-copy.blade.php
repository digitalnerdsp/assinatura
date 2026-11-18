@include('mail.partials.brand-header')
<p>Olá, {{ $recipientName }}.</p>
<p>O documento <strong>{{ $document->title }}</strong> foi assinado por todos e está concluído. A cópia assinada segue em anexo (PDF). Guarde este arquivo: ele é a sua via do documento.</p>
@if ($link)
<p>Para baixar de novo ou ver o documento quando quiser, use este link:<br><a href="{{ $link }}">{{ $link }}</a></p>
@endif
<p>Para conferir a autenticidade, use o código <strong>{{ $document->verification_code }}</strong> em:<br><a href="{{ $verifyUrl }}">{{ $verifyUrl }}</a></p>
