@extends('documents.wizard.layout')
@section('title', 'Criar documento')
@section('wizard')
<h1 class="wz-h1">Novo documento</h1>
<p class="wz-muted">Este passo ainda está sendo preparado.</p>
<div class="wz-foot"><a class="wz-btn wz-sec" href="{{ route('envelopes.step', [$envelope, max(1, $step - 1)]) }}"><i class="bx bx-left-arrow-alt" aria-hidden="true"></i> VOLTAR</a></div>
@endsection
