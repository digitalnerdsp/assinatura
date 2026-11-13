<?php

declare(strict_types=1);

namespace App\Plans;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageEntry;
use App\Models\User;

/**
 * Um só lugar para "esta empresa pode fazer isto agora?". Bloqueia apenas criar/enviar documento novo,
 * convidar usuário e gravar arquivo; assinar, baixar, verificar e webhooks nunca passam por aqui.
 */
final class Entitlements
{
    /** @param array{limits:array<string,?int>,features:array<string,bool>,consumption:array<string,mixed>} $effective */
    private function __construct(
        private readonly Tenant $tenant,
        private readonly Subscription $sub,
        private readonly array $effective,
    ) {
    }

    public static function for(Tenant $tenant): self
    {
        $svc = app(PlanService::class);
        $sub = $svc->subscriptionFor($tenant);
        $sub->loadMissing('plan');

        return new self($tenant, $sub, $svc->effective($sub));
    }

    public function subscription(): Subscription
    {
        return $this->sub;
    }

    public function feature(string $key): bool
    {
        return (bool) ($this->effective['features'][$key] ?? false);
    }

    /** Limite numérico; null = ilimitado. */
    public function limit(string $key): ?int
    {
        $v = $this->effective['limits'][$key] ?? null;

        return $v === null ? null : (int) $v;
    }

    /**
     * Regras de consumo normalizadas. A cota do período é `quota_amount` (se definida na regra) ou o limite de documentos.
     *
     * @param  array{limits:array<string,?int>,consumption:array<string,mixed>}  $effective
     * @return array{when:string,refund_unsigned:bool,balance:string,quota:?int,on_empty:string,overage_cents:int,cap:int}
     */
    public static function rulesFrom(array $effective): array
    {
        $c = $effective['consumption'] ?? [];
        $quota = $c['quota_amount'] ?? ($effective['limits']['documents'] ?? null);

        return [
            'when' => (string) ($c['when'] ?? 'send'),
            'refund_unsigned' => (bool) ($c['refund_unsigned'] ?? false),
            'balance' => (string) ($c['balance'] ?? 'quota'),
            'quota' => $quota === null ? null : (int) $quota,
            'on_empty' => (string) ($c['on_empty'] ?? 'block'),
            'overage_cents' => (int) ($c['overage_cents'] ?? 0),
            'cap' => (int) ($c['cap'] ?? 0),
        ];
    }

    /** @return array{when:string,refund_unsigned:bool,balance:string,quota:?int,on_empty:string,overage_cents:int,cap:int} */
    public function rules(): array
    {
        return self::rulesFrom($this->effective);
    }

    /** Vale para o envio de um documento novo (a cobrança de verdade é feita, com trava, por UsageLedger::charge). */
    public function canSendDocument(): Decision
    {
        $s = $this->sub;
        if (in_array($s->status, ['blocked', 'canceled'], true)) {
            return Decision::deny('blocked', 'A assinatura da sua empresa está '.($s->status === 'canceled' ? 'cancelada' : 'bloqueada').'. Regularize o plano para enviar novos documentos. Quem já recebeu um documento continua podendo assinar.');
        }
        if ($s->status === 'trialing' && $s->trial_ends_at !== null && $s->trial_ends_at->isPast() && ($s->plan->trial['on_end'] ?? null) === 'block') {
            return Decision::deny('blocked', 'O período de teste terminou. Escolha um plano para enviar novos documentos. Quem já recebeu um documento continua podendo assinar.');
        }

        $storageLimit = $this->limit('storage_mb');
        if ($storageLimit !== null && $this->storageBytes() >= $storageLimit * 1024 * 1024) {
            return Decision::deny('storage_limit', 'O armazenamento do seu plano está cheio. Apague documentos antigos ou troque de plano para enviar novos. Quem já recebeu um documento continua podendo assinar.');
        }

        $r = $this->rules();
        $mode = $r['balance'];
        $usesQuota = $mode !== 'credits' && $r['quota'] !== null;
        $usesCredits = $mode !== 'quota';
        if ($mode !== 'credits' && $r['quota'] === null) {
            return Decision::allow(); // sem limite
        }

        $used = $this->periodExpired() ? 0 : $s->quota_used;
        if (($usesQuota && $used < $r['quota']) || ($usesCredits && $s->credits_balance > 0)) {
            return Decision::allow();
        }
        if ($r['on_empty'] === 'overage') {
            return Decision::allow();
        }
        if ($r['on_empty'] === 'cap' && $this->overageUsed() < $r['cap']) {
            return Decision::allow();
        }

        return Decision::deny('documents_limit', 'Você usou todos os documentos do seu plano neste período. Peça mais documentos ou troque de plano para enviar novos. Quem já recebeu um documento continua podendo assinar.');
    }

    /** Novo PDF: tamanho máximo do plano e espaço que sobra no armazenamento. */
    public function canUpload(int $bytes): Decision
    {
        $maxMb = $this->limit('max_pdf_mb');
        if ($maxMb !== null && $bytes > $maxMb * 1024 * 1024) {
            $mb = number_format($bytes / 1048576, 1, ',', '.');

            return Decision::deny('pdf_too_large', "O PDF tem {$mb} MB e o seu plano aceita até {$maxMb} MB por arquivo.");
        }
        $storageMb = $this->limit('storage_mb');
        if ($storageMb !== null && $this->storageBytes() + $bytes > $storageMb * 1024 * 1024) {
            return Decision::deny('storage_limit', 'Este arquivo não cabe no armazenamento do seu plano. Apague documentos antigos ou troque de plano.');
        }

        return Decision::allow();
    }

    public function canInviteUser(): Decision
    {
        $limit = $this->limit('users');
        if ($limit !== null && $this->usersUsed() >= $limit) {
            return Decision::deny('users_limit', "Seu plano permite até {$limit} usuário".($limit === 1 ? '' : 's').'. Desative alguém ou troque de plano para convidar mais pessoas.');
        }

        return Decision::allow();
    }

    /** @return array{documents:array{used:int,limit:?int,credits:int},users:array{used:int,limit:?int},storage:array{used_bytes:int,limit_bytes:?int}} */
    public function usage(): array
    {
        $r = $this->rules();
        $storageMb = $this->limit('storage_mb');

        return [
            'documents' => ['used' => $this->periodExpired() ? 0 : $this->sub->quota_used, 'limit' => $r['balance'] === 'credits' ? null : $r['quota'], 'credits' => $this->sub->credits_balance],
            'users' => ['used' => $this->usersUsed(), 'limit' => $this->limit('users')],
            'storage' => ['used_bytes' => $this->storageBytes(), 'limit_bytes' => $storageMb === null ? null : $storageMb * 1024 * 1024],
        ];
    }

    /**
     * Cada limite do plano e quanto já foi usado (em %). Só entra o que tem limite.
     *
     * @return list<array{key:string,label:string,used:int,limit:int,pct:int}>
     */
    public function pressure(): array
    {
        $u = $this->usage();
        $r = $this->rules();
        $rows = [];
        if ($r['balance'] !== 'credits' && $r['quota'] !== null) {
            $rows[] = ['key' => 'documents', 'label' => 'documentos do período', 'used' => $u['documents']['used'], 'limit' => $r['quota']];
        }
        if ($u['users']['limit'] !== null) {
            $rows[] = ['key' => 'users', 'label' => 'usuários', 'used' => $u['users']['used'], 'limit' => $u['users']['limit']];
        }
        if ($u['storage']['limit_bytes'] !== null) {
            $rows[] = ['key' => 'storage', 'label' => 'armazenamento', 'used' => $u['storage']['used_bytes'], 'limit' => $u['storage']['limit_bytes']];
        }

        return array_map(fn ($m) => $m + ['pct' => $m['limit'] <= 0 ? 100 : intdiv($m['used'] * 100, $m['limit'])], $rows);
    }

    /** O limite mais apertado, ou null quando nada tem limite. @return array{key:string,label:string,used:int,limit:int,pct:int}|null */
    public function highest(): ?array
    {
        $rows = $this->pressure();
        usort($rows, fn ($a, $b) => $b['pct'] <=> $a['pct']);

        return $rows[0] ?? null;
    }

    public function overageUsed(): int
    {
        return UsageEntry::query()->where('tenant_id', $this->tenant->getKey())
            ->where('note', 'like', '%excedente%')
            ->when($this->sub->period_start, fn ($q, $from) => $q->where('occurred_at', '>=', $from))
            ->count();
    }

    /** Lido do banco na hora: o contador é atualizado por consulta direta, sem passar pelo modelo em memória. */
    private function storageBytes(): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('tenants')->where('id', $this->tenant->getKey())->value('storage_bytes');
    }

    private function periodExpired(): bool
    {
        return $this->sub->period_end !== null && $this->sub->period_end->isPast();
    }

    private function usersUsed(): int
    {
        return User::query()->where('tenant_id', $this->tenant->getKey())->whereNull('deactivated_at')->count();
    }
}
