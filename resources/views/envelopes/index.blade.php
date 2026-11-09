@extends('layouts.app')
@section('title', 'Envelopes')
@section('content')
<div class="row" style="align-items:center;margin-bottom:8px">
    <h1 style="margin:0">Envelopes</h1>
    <a class="btn right" style="flex:0 0 auto" href="{{ route('envelopes.create') }}">Novo envelope</a>
</div>
<div class="card">
@if ($envelopes->isEmpty())
    <p class="muted">Nenhum envelope ainda. Crie o primeiro para enviar um PDF para assinatura.</p>
@else
    <table>
        <thead><tr><th>Título</th><th>Status</th><th>Signatários</th><th>Criado em</th></tr></thead>
        <tbody>
        @foreach ($envelopes as $e)
            <tr>
                <td><a href="{{ route('envelopes.show', $e) }}">{{ $e->title }}</a></td>
                <td><span class="badge {{ $e->status->value }}">{{ __('status.'.$e->status->value) }}</span></td>
                <td>{{ $e->signers_count }}</td>
                <td>{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{ $envelopes->links() }}
@endif
</div>
@endsection
