@include('mail.partials.brand-header')
@php($n = abs($daysLeft))
<p>Olá.</p>
@if ($kind === 'before')
<p>O contrato <strong>{{ $contract->title }}</strong> vence em <strong>{{ $daysLeft }} {{ $daysLeft === 1 ? 'dia' : 'dias' }}</strong>, em {{ $contract->contract_expires_on->format('d/m/Y') }}.</p>
@elseif ($daysLeft === 0)
<p>O contrato <strong>{{ $contract->title }}</strong> vence <strong>hoje</strong> ({{ $contract->contract_expires_on->format('d/m/Y') }}).</p>
@else
<p>O contrato <strong>{{ $contract->title }}</strong> venceu há <strong>{{ $n }} {{ $n === 1 ? 'dia' : 'dias' }}</strong>, em {{ $contract->contract_expires_on->format('d/m/Y') }}.</p>
@endif
<p>Para renovar, trocar a data ou parar de monitorar, abra o contrato: <a href="{{ $url }}">{{ $url }}</a></p>
<p>Você recebeu este aviso porque seu e-mail foi indicado para lembrar do vencimento deste contrato.</p>
