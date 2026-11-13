@extends('layouts.app')
@section('title', 'Calendário')
@section('section', 'Calendário')
@section('content')
@php
    $weekdays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $hasEvents = collect($days)->contains(fn ($d) => $d['events'] !== []);
@endphp
<div class="card radius-10">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-outline-secondary drive-tap" href="{{ route('calendar.index', ['view' => $view, 'date' => $prev]) }}" aria-label="Anterior"><i class="bx bx-chevron-left"></i></a>
                <a class="btn btn-outline-secondary drive-tap" href="{{ route('calendar.index', ['view' => $view]) }}">Hoje</a>
                <a class="btn btn-outline-secondary drive-tap" href="{{ route('calendar.index', ['view' => $view, 'date' => $next]) }}" aria-label="Próximo"><i class="bx bx-chevron-right"></i></a>
                <h5 class="mb-0 ms-1">{{ $title }}</h5>
            </div>
            <div class="btn-group" role="group" aria-label="Visualização">
                <a class="btn drive-tap {{ $view === 'month' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('calendar.index', ['view' => 'month', 'date' => $anchor]) }}" @if ($view === 'month') aria-current="page" @endif>Mês</a>
                <a class="btn drive-tap {{ $view === 'week' ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('calendar.index', ['view' => 'week', 'date' => $anchor]) }}" @if ($view === 'week') aria-current="page" @endif>Semana</a>
            </div>
        </div>

        <ul class="list-unstyled d-flex flex-wrap gap-3 mb-3">
            @foreach ($types as $key => $label)
                <li class="d-flex align-items-center gap-1"><span class="cal-dot cal-{{ $key }}"></span><span class="fw-semibold">{{ $label }}</span></li>
            @endforeach
        </ul>

        {{-- Grade (a partir de 576 px) --}}
        <div class="cal-grid {{ $view === 'week' ? 'is-week' : '' }} d-none d-sm-grid">
            @foreach ($weekdays as $w)<div class="cal-head">{{ $w }}</div>@endforeach
            @foreach ($days as $d)
                <div class="cal-day {{ $d['in_range'] ? '' : 'is-out' }} {{ $d['today'] ? 'is-today' : '' }}">
                    <div class="cal-num">{{ $d['date']->day }}</div>
                    @foreach ($d['events'] as $ev)
                        <a class="cal-ev cal-{{ $ev['type'] }}" href="{{ route('envelopes.show', $ev['envelope_id']) }}" title="{{ $types[$ev['type']] }}: {{ $ev['title'] }}"><span class="visually-hidden">{{ $types[$ev['type']] }}: </span>{{ $ev['title'] }}</a>
                    @endforeach
                </div>
            @endforeach
        </div>

        {{-- Lista por dia (celular) --}}
        <div class="d-sm-none">
            @if (! $hasEvents)
                <p class="text-secondary mb-0">Nenhum evento neste período.</p>
            @endif
            @foreach ($days as $d)
                @if ($d['events'] !== [] && $d['in_range'])
                    <div class="cal-list-day {{ $d['today'] ? 'is-today' : '' }}">
                        @php($label = $d['date']->locale('pt_BR')->translatedFormat('l, j \d\e F'))
                        <div class="fw-bold mb-1">{{ mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1) }}@if ($d['today']) <span class="badge bg-primary">Hoje</span>@endif</div>
                        @foreach ($d['events'] as $ev)
                            <a class="cal-ev cal-{{ $ev['type'] }} drive-tap" href="{{ route('envelopes.show', $ev['envelope_id']) }}"><strong>{{ $types[$ev['type']] }}:</strong>&nbsp;{{ $ev['title'] }}</a>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</div>
@endsection
