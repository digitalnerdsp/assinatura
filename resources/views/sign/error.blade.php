@extends('layouts.public')
@section('title', 'Link indisponível')
@section('max', '560px')
@section('content')
<div class="card radius-10 text-center">
    <div class="card-body p-5">
        <div class="widgets-icons-2 rounded-circle bg-gradient-orange text-white mx-auto mb-3"><i class="bx bx-link-alt"></i></div>
        <h4>Link indisponível</h4>
        <p class="text-secondary mb-0">{{ $message }}</p>
    </div>
</div>
@endsection
