@extends('layouts.app')
@section('title', 'Minhas assinaturas')
@section('section', 'Documentos')
@section('content')
<div class="card radius-10">
    <div class="card-body">
        <p class="text-secondary">Documentos da sua empresa em que o seu e-mail está como signatário.</p>
        @if ($signers->isEmpty())
            <div class="text-center py-5"><i class="bx bx-pen fs-1 text-secondary"></i><p class="text-secondary mt-2">Nenhum documento aguardando ou registrando a sua assinatura.</p></div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Documento</th><th>Status do documento</th><th>Minha assinatura</th><th>Enviado em</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($signers as $s)
                        <tr>
                            <td class="fw-semibold">{{ $s->envelope->title }}</td>
                            <td>@include('partials.status-badge', ['status' => $s->envelope->status->value])</td>
                            <td>@include('partials.status-badge', ['status' => $s->status->value])</td>
                            <td>{{ $s->envelope->sent_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="text-end">
                                @if ($s->can_sign && $s->signUrl())
                                    <a class="btn btn-sm btn-primary" href="{{ $s->signUrl() }}"><i class="bx bx-pen"></i> Assinar</a>
                                @elseif ($s->waiting_turn)
                                    <span class="text-secondary small">Aguardando a sua vez</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $signers->links() }}</div>
        @endif
    </div>
</div>
@endsection
