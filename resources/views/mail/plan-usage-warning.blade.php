<p>Olá.</p>
@if ($full)
<p>A empresa <strong>{{ $tenant->name }}</strong> chegou ao limite do plano:</p>
@else
<p>A empresa <strong>{{ $tenant->name }}</strong> já usou 80% ou mais do plano:</p>
@endif
<ul>
@foreach ($items as $item)
    <li><strong>{{ ucfirst($item['label']) }}:</strong> {{ $item['used'] }} de {{ $item['limit'] }} ({{ min(100, $item['pct']) }}%)</li>
@endforeach
</ul>
@if ($full)
<p>Enquanto o limite estiver estourado, não é possível criar nem enviar novos documentos. Quem já recebeu um documento continua podendo assinar, e você continua baixando e verificando tudo normalmente.</p>
@else
<p>Ao chegar a 100%, novos documentos deixam de ser enviados. Quem já recebeu um documento continua podendo assinar.</p>
@endif
<p>Para aumentar o limite, fale com o suporte ou troque de plano.</p>
