<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Contracts\ContractWatch;
use App\Enums\EnvelopeStatus;
use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Define, renova ou remove a vigência (vencimento e aviso) de um documento já criado. */
final class ContractTermController extends Controller
{
    public function __construct(private readonly ContractWatch $watch)
    {
    }

    public function save(Request $request, Envelope $envelope): RedirectResponse
    {
        $this->owned($request, $envelope);
        if ($envelope->status === EnvelopeStatus::Draft) {
            return back()->withErrors(['contract' => 'Este documento ainda é um rascunho: defina o vencimento no último passo do assistente.']);
        }

        $data = $request->validate([
            'contract_date' => ['nullable', 'string', 'max:10'],
            'contract_alert_days' => ['nullable', 'integer'],
            'contract_emails' => ['nullable', 'max:600'],
            'contract_emails.*' => ['string', 'max:190'],
        ]);

        // Os e-mails chegam como lista ou como texto separado por vírgula, ponto e vírgula ou espaço.
        $raw = $data['contract_emails'] ?? [];
        $emails = is_array($raw) ? $raw : (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        try {
            $this->watch->setTerm($envelope, (string) ($data['contract_date'] ?? ''), (int) ($data['contract_alert_days'] ?? 0), array_map('strval', $emails));
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['contract' => $e->getMessage()]);
        }

        return redirect()->route('envelopes.show', $envelope)->with('status', 'Vigência do contrato salva.');
    }

    public function clear(Request $request, Envelope $envelope): RedirectResponse
    {
        $this->owned($request, $envelope);
        $this->watch->clearTerm($envelope);

        return redirect()->route('envelopes.show', $envelope)->with('status', 'Vigência do contrato removida.');
    }

    private function owned(Request $request, Envelope $envelope): void
    {
        abort_unless($envelope->tenant_id === $request->user()->tenant_id && ! $envelope->isTrashed(), 404);
    }
}
