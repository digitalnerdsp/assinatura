@extends('layouts.app')
@section('title', 'Documentos criados')
@section('section', 'Documentos')
@section('actions')<a href="{{ route('envelopes.create') }}" class="btn btn-primary"><i class="bx bx-plus"></i> Criar documento</a>@endsection
@section('content')
@include('envelopes._list', ['listRoute' => 'envelopes.index', 'showFolderSelect' => true])

@include('envelopes._drive_modals')
@endsection

@push('scripts')
<script src="{{ asset('assets/js/drive.js') }}?v=2"></script>
@endpush
