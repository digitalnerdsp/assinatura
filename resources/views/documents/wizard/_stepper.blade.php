@php
    $labels = [1 => 'Selecionar documento', 2 => 'Adicionar signatários', 3 => 'Posicionar assinaturas', 4 => 'Enviar documento'];
    $dot = function (int $n) use ($step, $reached) {
        if ($n === $step) { return $reached >= $n ? '<i class="bx bx-pencil" aria-hidden="true"></i>' : (string) $n; }
        return $n <= $reached ? '<i class="bx bx-check" aria-hidden="true"></i>' : (string) $n;
    };
    $cls = fn (int $n) => 'wz-st'.($n === $step ? ' is-cur' : '').($n === $step && $reached >= $n ? ' is-edit' : '').($n < $step || ($n !== $step && $n <= $reached) ? ' is-done' : '');
@endphp
<nav aria-label="Passos para criar o documento">
    <ol class="wz-stepper-h">
        @foreach ($labels as $n => $label)
            @if ($n > 1)<li class="wz-ln" aria-hidden="true"></li>@endif
            <li class="{{ $cls($n) }}" @if ($n === $step) aria-current="step" @endif><span class="wz-dot">{!! $dot($n) !!}</span><span class="wz-st-t">{{ $label }}</span></li>
        @endforeach
    </ol>
    <ol class="wz-stepper-v">
        @foreach ($labels as $n => $label)
            <li class="{{ $cls($n) }}" data-n="{{ $n }}"><span class="wz-st-row"><span class="wz-dot">{!! $dot($n) !!}</span><span class="wz-st-t">{{ $label }}</span></span><div class="wz-slot"></div></li>
        @endforeach
    </ol>
</nav>
