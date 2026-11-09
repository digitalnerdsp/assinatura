{{-- Card KPI do template Rocker. $label, $value, $icon, $tone (info|danger|success|warning), $grad, $hint (opcional), $delta (opcional) --}}
<div class="col">
    <div class="card radius-10 border-start border-0 border-4 border-{{ $tone }}">
        <div class="card-body">
            <div class="d-flex align-items-center">
                <div>
                    <p class="mb-0 text-secondary">{{ $label }}</p>
                    <h4 class="my-1 text-{{ $tone }}">{{ $value }}</h4>
                    <p class="mb-0 font-13">
                        @isset($delta)
                            @if ($delta !== null)
                                <span class="{{ $delta >= 0 ? 'text-success' : 'text-danger' }}"><i class="bx {{ $delta >= 0 ? 'bx-up-arrow-alt' : 'bx-down-arrow-alt' }} align-middle"></i>{{ abs($delta) }}%</span> vs. mês anterior
                            @else
                                {{ $hint ?? '' }}
                            @endif
                        @else
                            {{ $hint ?? '' }}
                        @endisset
                    </p>
                </div>
                <div class="widgets-icons-2 rounded-circle bg-gradient-{{ $grad }} text-white ms-auto"><i class="bx {{ $icon }}"></i></div>
            </div>
        </div>
    </div>
</div>
