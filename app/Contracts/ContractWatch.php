<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\EnvelopeStatus;
use App\Envelopes\Exceptions\FlowException;
use App\Models\Envelope;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vigência de contratos: regras da data de vencimento, faixas, janela "a vencer" e quais documentos são monitorados.
 * Tudo no calendário de Brasília; o contrato vence ao fim do dia escolhido.
 */
final class ContractWatch
{
    public const TZ = 'America/Sao_Paulo';

    /** Dias de antecedência que a pessoa pode escolher para o aviso. */
    public const ALERT_DAYS = [7, 15, 30, 60, 90];

    public const MAX_EMAILS = 5;

    /** A tela "a vencer" mostra pelo menos esta janela, mesmo com aviso menor. */
    public const MIN_WINDOW = 30;

    public const MAX_YEARS = 20;

    /** Hoje em Brasília, à meia-noite. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /**
     * Define (ou renova) a vigência. Qualquer mudança apaga os avisos já enviados: a data nova recomeça do zero.
     *
     * @param  list<string>  $emails
     */
    public function setTerm(Envelope $envelope, string $dueOn, int $alertDays, array $emails): void
    {
        $clean = $this->validateTerm($dueOn, $alertDays, $emails);

        $dateChanged = $envelope->contract_expires_on?->toDateString() !== $dueOn;
        $envelope->forceFill(['contract_expires_on' => $dueOn, 'contract_alert_days' => $alertDays, 'contract_alert_emails' => $clean])->save();
        if ($dateChanged) {
            $envelope->contractAlerts()->delete(); // data nova recomeça do zero; trocar só e-mails ou prazo não reenvia o que já saiu
        }
    }

    /**
     * Confere os dados da vigência sem gravar nada e devolve os e-mails já limpos.
     *
     * @param  list<string>  $emails
     * @return list<string>
     */
    public function validateTerm(string $dueOn, int $alertDays, array $emails): array
    {
        $today = $this->today();
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $dueOn) !== 1 || ! checkdate((int) substr($dueOn, 5, 2), (int) substr($dueOn, 8, 2), (int) substr($dueOn, 0, 4))) {
            throw FlowException::because('invalid_term', 'Informe a data de vencimento em um formato válido.');
        }
        $due = CarbonImmutable::parse($dueOn, self::TZ)->startOfDay();
        if ($due->lte($today)) {
            throw FlowException::because('invalid_term', 'A data de vencimento precisa ser depois de hoje.');
        }
        if ($due->gt($today->addYears(self::MAX_YEARS))) {
            throw FlowException::because('invalid_term', 'A data de vencimento pode ser no máximo daqui a '.self::MAX_YEARS.' anos.');
        }
        if (! in_array($alertDays, self::ALERT_DAYS, true)) {
            throw FlowException::because('invalid_term', 'Escolha avisar com '.implode(', ', self::ALERT_DAYS).' dias de antecedência.');
        }

        $clean = [];
        foreach ($emails as $email) {
            $email = mb_strtolower(trim((string) $email));
            if ($email === '') {
                continue;
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                throw FlowException::because('invalid_term', "O e-mail {$email} não parece válido.");
            }
            $clean[$email] = $email;
        }
        $clean = array_values($clean);
        if ($clean === []) {
            throw FlowException::because('invalid_term', 'Informe ao menos um e-mail para receber o aviso.');
        }
        if (count($clean) > self::MAX_EMAILS) {
            throw FlowException::because('invalid_term', 'Use no máximo '.self::MAX_EMAILS.' e-mails para o aviso.');
        }

        return $clean;
    }

    public function clearTerm(Envelope $envelope): void
    {
        $envelope->forceFill(['contract_expires_on' => null, 'contract_alert_days' => null, 'contract_alert_emails' => null])->save();
        $envelope->contractAlerts()->delete();
    }

    /** Dias que faltam (negativo = vencido; 0 = vence hoje). Nulo se não há vigência. */
    public function daysLeft(Envelope $envelope, ?CarbonImmutable $today = null): ?int
    {
        if ($envelope->contract_expires_on === null) {
            return null;
        }
        $due = CarbonImmutable::parse($envelope->contract_expires_on->toDateString(), self::TZ)->startOfDay();

        return (int) round(($today ?? $this->today())->diffInDays($due, false));
    }

    /** @return 'overdue'|'d30'|'d60'|'d90'|'valid' */
    public function band(int $daysLeft): string
    {
        return match (true) {
            $daysLeft < 0 => 'overdue',
            $daysLeft <= 30 => 'd30',
            $daysLeft <= 60 => 'd60',
            $daysLeft <= 90 => 'd90',
            default => 'valid',
        };
    }

    /** Janela "a vencer" deste contrato: 30 dias ou o aviso escolhido, o que for maior. */
    public function windowDays(Envelope $envelope): int
    {
        return max(self::MIN_WINDOW, (int) $envelope->contract_alert_days);
    }

    /** Vencido ou dentro da janela "a vencer". */
    public function dueSoon(Envelope $envelope, ?CarbonImmutable $today = null): bool
    {
        $left = $this->daysLeft($envelope, $today);

        return $left !== null && $left <= $this->windowDays($envelope);
    }

    /**
     * Resumo do painel: quantos estão a vencer (janela de cada contrato), quantos pedem atenção
     * (vencidos ou vencendo em até 7 dias) e os 5 mais próximos.
     *
     * @return array{soon:int,attention:int,items:\Illuminate\Support\Collection<int,Envelope>}
     */
    public function dashboard(Tenant $tenant): array
    {
        $today = $this->today()->toDateString();
        $soon = $this->monitored($tenant)->whereRaw('contract_expires_on <= cast(? as date) + GREATEST(30, COALESCE(contract_alert_days, 30))', [$today]);

        return [
            'soon' => (clone $soon)->count(),
            'attention' => (clone $soon)->whereRaw('contract_expires_on <= cast(? as date) + 7', [$today])->count(),
            'items' => (clone $soon)->orderBy('contract_expires_on')->orderByRaw('lower(title)')->limit(5)->get(),
        ];
    }

    /** Documentos monitorados da empresa: concluídos, fora da lixeira (arquivados contam) e com vencimento. @return Builder<Envelope> */
    public function monitored(Tenant $tenant): Builder
    {
        return Envelope::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', EnvelopeStatus::Completed->value)
            ->whereNull('trashed_at')
            ->whereNotNull('contract_expires_on');
    }
}
