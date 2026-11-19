{{-- Cartão "Vigência do contrato": definir, renovar ou remover o vencimento e o aviso. Não aparece em rascunho nem na lixeira. --}}
@php
    $watch = app(\App\Contracts\ContractWatch::class);
    $left = $watch->daysLeft($envelope);
    $band = $left === null ? null : $watch->band($left);
    $tone = ['overdue' => 'danger', 'd30' => 'warning', 'd60' => 'warning', 'd90' => 'info', 'valid' => 'success'][$band] ?? 'secondary';
    $emailsText = old('contract_emails', implode(', ', (array) $envelope->contract_alert_emails));
    $plural = fn (int $n) => $n === 1 ? 'dia' : 'dias';
    $label = $left === null ? '' : ($left < 0 ? 'Vencido há '.abs($left).' '.$plural(abs($left)) : ($left === 0 ? 'Vence hoje' : $left.' '.$plural($left).' para vencer'));
@endphp
<div class="card radius-10" id="vigencia">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h6 class="mb-0"><i class="bx bx-calendar-check me-1"></i> Vigência do contrato</h6>
        @if ($left !== null)
            <span class="badge bg-{{ $tone }} {{ in_array($tone, ['warning', 'info']) ? 'text-dark' : '' }} ms-auto">{{ $label }}</span>
        @endif
    </div>
    <div class="card-body">
        @error('contract')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
        @if ($envelope->contract_expires_on)
            <p class="mb-2"><strong>Vence em {{ $envelope->contract_expires_on->format('d/m/Y') }}</strong>
                {{ $left !== null && $left >= 0 ? '('.$left.' '.$plural($left).')' : '' }}
                · avisar <strong>{{ $envelope->contract_alert_days }} dias antes</strong> e no dia do vencimento, para {{ implode(', ', (array) $envelope->contract_alert_emails) }}.</p>
        @else
            <p class="text-secondary mb-2">Sem vencimento monitorado. Informe a data para receber um aviso por e-mail e acompanhar este contrato na tela Contratos.</p>
        @endif
        @if ($envelope->status !== \App\Enums\EnvelopeStatus::Completed)
            <p class="small text-secondary">O aviso só vale depois que todos assinarem; até lá o contrato não entra na tela Contratos.</p>
        @endif
        <form method="post" action="{{ route('envelopes.term.save', $envelope) }}" class="row g-2 align-items-end">
            @csrf @method('PUT')
            <div class="col-12 col-md-3">
                <label for="ct-date" class="form-label">Data de vencimento</label>
                <input type="date" id="ct-date" class="form-control" name="contract_date" value="{{ old('contract_date', $envelope->contract_expires_on?->toDateString()) }}" min="{{ now('America/Sao_Paulo')->addDay()->toDateString() }}" max="{{ now('America/Sao_Paulo')->addYears(20)->toDateString() }}" required>
            </div>
            <div class="col-6 col-md-2">
                <label for="ct-days" class="form-label">Avisar antes</label>
                <select id="ct-days" class="form-select" name="contract_alert_days">
                    @foreach (\App\Contracts\ContractWatch::ALERT_DAYS as $d)<option value="{{ $d }}" @selected((int) old('contract_alert_days', $envelope->contract_alert_days ?? 30) === $d)>{{ $d }} dias</option>@endforeach
                </select>
            </div>
            <div class="col-12 col-md-5">
                <label for="ct-mails" class="form-label">E-mails do aviso <span class="text-secondary fw-normal">(até {{ \App\Contracts\ContractWatch::MAX_EMAILS }}, separados por vírgula)</span></label>
                <input type="text" id="ct-mails" class="form-control" name="contract_emails" value="{{ $emailsText ?: auth()->user()->email }}" maxlength="500" required>
            </div>
            <div class="col-6 col-md-2 d-grid">
                <button class="btn btn-primary">{{ $envelope->contract_expires_on ? 'Renovar / salvar' : 'Salvar' }}</button>
            </div>
        </form>
        @if ($envelope->contract_expires_on)
            <form method="post" action="{{ route('envelopes.term.clear', $envelope) }}" class="mt-2" data-confirm="Parar de monitorar o vencimento deste contrato?">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-secondary"><i class="bx bx-trash"></i> Remover vigência</button>
            </form>
        @endif
    </div>
</div>
