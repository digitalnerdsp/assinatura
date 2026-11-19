<?php

declare(strict_types=1);

namespace App\Assistant\Notifications;

use App\Assistant\AssistantSettings;
use App\Assistant\Metering\WhatsAppCredit;
use App\Jobs\SendWhatsAppNotification;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Avisa o usuário pelo WhatsApp com um modelo aprovado. Só envia se o usuário vinculou o número e quer o aviso,
 * o modelo está aprovado e ligado, a categoria tem preço cadastrado e há saldo. Nunca envia o mesmo aviso duas vezes
 * (chave de deduplicação), reserva o custo estimado até a Meta cobrar o real e nunca quebra o fluxo de quem chamou.
 */
final class WhatsAppNotifier
{
    public function __construct(
        private readonly TemplateStore $templates,
        private readonly AssistantSettings $settings,
        private readonly WhatsAppCredit $credit,
    ) {
    }

    /**
     * Motivo para NÃO enviar a este usuário, ou null se pode. Conferido ao enfileirar e de novo na hora de enviar.
     *
     * @return string|null no_user | not_linked | platform | no_feature | pref_off | no_template
     */
    public function skipReason(?User $user, string $key): ?string
    {
        if ($user === null || ! $user->isActive() || $user->isPendingInvite()) {
            return 'no_user';
        }
        if ($user->whatsapp_phone === null || $user->whatsapp_verified_at === null) {
            return 'not_linked';
        }
        $tenant = $user->tenant;
        if ($tenant === null || $tenant->isSuspended() || $tenant->isPlatformOwned()) {
            return 'platform'; // super administrador e empresa suspensa não recebem
        }
        if (! \App\Plans\Entitlements::for($tenant)->feature('assistant')) {
            return 'no_feature';
        }
        if (! $user->wantsWhatsAppNotice($key)) {
            return 'pref_off';
        }

        return $this->templates->usable($key) === null ? 'no_template' : null;
    }

    /** Custo estimado de um aviso: preço da categoria do modelo com o acréscimo; null se a categoria não tem preço. */
    public function estimate(string $key): ?float
    {
        $template = $this->templates->usable($key);
        $rate = $template !== null ? $this->settings->rateFor($template->category) : null;

        return $rate === null ? null : round($rate * (1 + $this->settings->markupPercent() / 100), 6);
    }

    /**
     * @param  list<string>  $params valores das variáveis do modelo, em ordem
     * @return string sent_queued | duplicate | no_user | not_linked | platform | no_feature | pref_off | no_template | no_price | no_balance
     */
    public function notify(?User $user, string $key, array $params, string $dedupe): string
    {
        if (($why = $this->skipReason($user, $key)) !== null) {
            return $why;
        }
        $estimate = $this->estimate($key);
        if ($estimate === null) {
            return 'no_price'; // sem preço da categoria cadastrado, nada é enviado (não inventa valor)
        }
        $tenant = $user->tenant;

        // A checagem de saldo e a reserva do custo acontecem juntas, com a empresa travada: avisos simultâneos não furam o saldo.
        $result = DB::transaction(function () use ($tenant, $user, $key, $params, $dedupe, $estimate): array {
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();
            if (! $this->credit->canAfford($tenant, $estimate)) {
                return ['no_balance', null];
            }
            $id = (string) Str::uuid();
            $row = WhatsAppNotification::query()->insertOrIgnore([[
                'id' => $id, 'tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey(), 'template_key' => $key,
                'dedupe_key' => mb_substr($dedupe, 0, 120), 'status' => 'queued', 'params' => json_encode(array_values(array_map('strval', $params))),
                'reserved_amount' => $tenant->isPlatformOwned() ? 0 : $estimate, 'created_at' => now(), 'updated_at' => now(),
            ]]);

            return [$row === 0 ? 'duplicate' : 'sent_queued', $row === 0 ? null : $id];
        });

        if ($result[1] !== null) {
            try {
                SendWhatsAppNotification::dispatch($result[1]);
            } catch (Throwable $e) {
                report($e); // a linha fica na fila e o faxineiro (whatsapp:notify-janitor) a reenvia
            }
        }

        return $result[0];
    }

    /** Avisa quem criou o documento. Nunca lança: o aviso é um extra e não pode atrapalhar a assinatura. */
    public function toCreator(Envelope $envelope, string $key, array $params, string $dedupe): string
    {
        try {
            $user = $envelope->created_by !== null ? User::query()->find($envelope->created_by) : null;

            return $this->notify($user, $key, $params, $dedupe);
        } catch (Throwable $e) {
            report($e);

            return 'error';
        }
    }
}
