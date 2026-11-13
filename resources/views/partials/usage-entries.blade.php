@php
    $kinds = ['quota' => 'Cota', 'credit' => 'Crédito', 'refund' => 'Devolução', 'adjust' => 'Ajuste'];
@endphp
@if ($entries->isEmpty())
    <p class="text-secondary mb-0">Nenhum lançamento ainda.</p>
@else
    <div class="table-responsive">
        <table class="table align-middle mb-0 drive-docs">
            <thead class="table-light"><tr><th>Data</th><th>Tipo</th><th>Variação</th><th>Saldo depois</th><th>Detalhe</th></tr></thead>
            <tbody>
            @foreach ($entries as $e)
                <tr>
                    <td data-label="Data">{{ $e->occurred_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                    <td data-label="Tipo">{{ $kinds[$e->kind] ?? $e->kind }}</td>
                    <td data-label="Variação" class="fw-semibold">{{ $e->delta > 0 ? '+' : '' }}{{ $e->delta }}</td>
                    <td data-label="Saldo depois">{{ $e->balance_after }}</td>
                    <td data-label="Detalhe">{{ $e->note }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
