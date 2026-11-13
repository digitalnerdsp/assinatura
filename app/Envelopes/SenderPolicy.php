<?php

declare(strict_types=1);

namespace App\Envelopes;

use App\Envelopes\Exceptions\FlowException;
use App\Models\Tenant;
use App\Models\TenantSigningSetting;

/** Política da empresa para a assinatura do remetente: always (padrão), optional ou never. */
final class SenderPolicy
{
    public function policy(Tenant $tenant): string
    {
        return TenantSigningSetting::query()->where('tenant_id', $tenant->getKey())->value('sender_policy') ?? 'always';
    }

    /**
     * O remetente deve assinar este envelope? `$requested` é o que o usuário/API pediu (null = não pediu).
     *
     * @throws FlowException `sender_signature_required` (always + false) ou `sender_signature_disabled` (never + true)
     */
    public function wantsSenderSignature(Tenant $tenant, ?bool $requested): bool
    {
        $policy = $this->policy($tenant);

        if ($policy === 'always') {
            if ($requested === false) {
                throw FlowException::because('sender_signature_required', 'Sua empresa exige que o remetente assine o documento.');
            }

            return true;
        }

        if ($policy === 'never') {
            if ($requested === true) {
                throw FlowException::because('sender_signature_disabled', 'Sua empresa não permite que o remetente assine.');
            }

            return false;
        }

        return $requested ?? false;
    }
}
