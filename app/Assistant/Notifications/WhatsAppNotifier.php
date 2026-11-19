<?php

declare(strict_types=1);

namespace App\Assistant\Notifications;

use App\Assistant\AssistantSettings;
use App\Assistant\Metering\WhatsAppCredit;
use App\Jobs\SendWhatsAppNotification;
use App\Models\Envelope;
use App\Models\User;
use App\Models\WhatsAppNotification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Avisa o usuário pelo WhatsApp com um modelo aprovado. Só envia se o usuário vinculou o número e quer o aviso,
 * o modelo está aprovado e ligado, a categoria tem preço cadastrado e há saldo. Nunca envia o mesmo aviso duas vezes
 * (chave de deduplicação) e nunca quebra o fluxo de quem chamou.
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
     * @param  list<string>  $params valores das variáveis do modelo, em ordem
     * @return string sent_queued | duplicate | no_user | not_linked | platform | no_feature | pref_off | no_template | no_price | no_balance
     */
    public function notify(?User $user, string $key, array $params, string $dedupe): string
    {
        if ($user === null || ! $user->isActive() || $user->isPendingInvite()) {
            return 'no_user';
        }
        if ($user->whatsapp_phone === null || $user->whatsapp_verified_at === null) {
            return 'not_linked';
        }
        $tenant = $user->tenant;
        if ($tenant === null || $tenant->isSuspended() || $tenant->isPlatformOwned()) {
            return 'platform'; // super administrador não recebe avisos de plataforma
        }
        if (! \App\Plans\Entitlements::for($tenant)->feature('assistant')) {
            return 'no_feature';
        }
        if (! $user->wantsWhatsAppNotice($key)) {
            return 'pref_off';
        }
        $template = $this->templates->usable($key);
        if ($template === null) {
            return 'no_template';
        }
        $rate = $this->settings->rateFor($template->category);
        if ($rate === null) {
            return 'no_price'; // sem preço da categoria cadastrado, nada é enviado (não inventa valor)
        }
        $estimate = round($rate * (1 + $this->settings->markupPercent() / 100), 6);
        if (! $this->credit->canAfford($tenant, $estimate)) {
            return 'no_balance';
        }

        $row = WhatsAppNotification::query()->insertOrIgnore([[
            'id' => $id = (string) Str::uuid(), 'tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey(), 'template_key' => $key,
            'dedupe_key' => mb_substr($dedupe, 0, 120), 'status' => 'queued', 'params' => json_encode(array_values(array_map('strval', $params))),
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        if ($row === 0) {
            return 'duplicate';
        }

        SendWhatsAppNotification::dispatch($id);

        return 'sent_queued';
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
