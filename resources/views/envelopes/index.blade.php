@extends('layouts.app')
@section('title', 'Envelopes')
@section('section', 'Assinaturas')
@section('actions')<a href="{{ route('envelopes.create') }}" class="btn btn-primary"><i class="bx bx-plus"></i> Novo envelope</a>@endsection
@section('content')
<div class="card radius-10">
    <div class="card-body">
        @if ($envelopes->isEmpty())
            <div class="text-center py-5">
                <i class="bx bx-file fs-1 text-secondary"></i>
                <p class="text-secondary mt-2">Nenhum envelope ainda. Crie o primeiro para enviar um PDF para assinatura.</p>
                <a class="btn btn-primary" href="{{ route('envelopes.create') }}">Criar envelope</a>
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Título</th><th>Status</th><th>Signatários</th><th>Criado em</th></tr></thead>
                    <tbody>
                    @foreach ($envelopes as $e)
                        <tr>
                            <td><a href="{{ route('envelopes.show', $e) }}" class="fw-semibold">{{ $e->title }}</a></td>
                            <td>@include('partials.status-badge', ['status' => $e->status->value])</td>
                            <td>{{ $e->signers_count }}</td>
                            <td>{{ $e->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $envelopes->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection
