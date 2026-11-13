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
     * Representante que assina pela empresa na API. Só existe com nome, e-mail e o termo de delegação aceito.
     *
     * @return array{name:string,email:string,cpf:?string}|null
     */
    public function representative(Tenant $tenant): ?array
    {
        $s = TenantSigningSetting::query()->where('tenant_id', $tenant->getKey())->first();

        if ($s === null || $s->delegation_accepted_at === null
            || trim((string) $s->representative_name) === '' || trim((string) $s->representative_email) === '') {
            return null;
        }

        return ['name' => $s->representative_name, 'email' => $s->representative_email, 'cpf' => $s->representative_cpf];
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
