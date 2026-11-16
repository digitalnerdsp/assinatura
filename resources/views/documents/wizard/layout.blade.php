@extends('layouts.app')
@section('section', 'Documentos')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/css/wizard.css') }}?v=1">
<div class="wz" id="wz">
    <div class="wz-card" id="wz-card">
        @include('documents.wizard._stepper')
        <div class="wz-panel" id="wz-panel">
            @if (session('status'))<div class="wz-flash" role="status">{{ session('status') }}</div>@endif
            @yield('wizard')
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('assets/js/wizard.js') }}?v=1"></script>
@endpush
