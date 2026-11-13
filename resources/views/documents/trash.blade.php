@extends('layouts.app')
@section('title', 'Lixeira')
@section('section', 'Documentos')
@section('content')
<div class="card radius-10">
    <div class="card-body">
        <p class="text-secondary">Rascunhos, cancelados e expirados ficam aqui por {{ $days }} dias e depois são apagados de vez. Documentos assinados nunca vão para a lixeira: use <strong>Arquivar</strong>.</p>
        @if ($items->isEmpty())
            <div class="text-center py-5"><i class="bx bx-trash fs-1 text-secondary"></i><p class="text-secondary mt-2">A lixeira está vazia.</p></div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Título</th><th>Status</th><th>Na lixeira desde</th><th>Prazo</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($items as $e)
                        @php($left = max(0, (int) ceil($days - $e->trashed_at->diffInDays(now(), true))))
                        <tr>
                            <td class="fw-semibold">{{ $e->title }}</td>
                            <td>@include('partials.status-badge', ['status' => $e->status->value])</td>
                            <td>{{ $e->trashed_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                            <td>apagado em {{ $left }} {{ $left === 1 ? 'dia' : 'dias' }}</td>
                            <td class="text-end"><form method="post" action="{{ route('envelopes.restore', $e) }}">@csrf<button class="btn btn-sm btn-outline-primary"><i class="bx bx-undo"></i> Restaurar</button></form></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $items->links() }}</div>
        @endif
    </div>
</div>
@endsection
