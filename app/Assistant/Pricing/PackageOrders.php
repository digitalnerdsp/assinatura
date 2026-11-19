<?php

declare(strict_types=1);

namespace App\Assistant\Pricing;

use App\Assistant\Metering\TokenWallet;
use App\Models\AssistantPackage;
use App\Models\AssistantPackageOrder;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Pedidos de pacote de tokens: a empresa pede, o super administrador libera (ou recusa). A liberação credita a carteira uma vez só. */
final class PackageOrders
{
    public const MAX_PENDING = 3;

    public function __construct(private readonly TokenWallet $wallet)
    {
    }

    /** @throws DomainException regra de negócio (pacote indisponível, pedido repetido, limite de pendentes) */
    public function request(User $user, AssistantPackage $package): AssistantPackageOrder
    {
        if (! $package->active) {
            throw new DomainException('Este pacote não está disponível.');
        }

        return DB::transaction(function () use ($user, $package): AssistantPackageOrder {
            Tenant::query()->whereKey($user->tenant_id)->lockForUpdate()->first(); // pedidos simultâneos da mesma empresa passam um por vez
            $pending = AssistantPackageOrder::query()->where('tenant_id', $user->tenant_id)->where('status', 'pending');
            if ((clone $pending)->where('package_id', $package->getKey())->exists()) {
                throw new DomainException('Você já tem um pedido deste pacote aguardando liberação.');
            }
            if ((clone $pending)->count() >= self::MAX_PENDING) {
                throw new DomainException('Você já tem '.self::MAX_PENDING.' pedidos aguardando liberação. Espere a liberação ou cancele um deles.');
            }

            return AssistantPackageOrder::query()->create([
                'tenant_id' => $user->tenant_id, 'package_id' => $package->getKey(), 'name' => $package->name, 'tokens' => $package->tokens,
                'price_cents' => $package->price_cents, 'validity_months' => $package->validity_months, 'status' => 'pending', 'requested_by' => $user->getKey(),
            ]);
        });
    }

    /** Libera o pedido: reivindica por UPDATE no banco (só um vence) e credita os tokens com a validade do pacote, na mesma transação. */
    public function approve(AssistantPackageOrder $order, string $by): void
    {
        DB::transaction(function () use ($order, $by): void {
            $claimed = AssistantPackageOrder::query()->whereKey($order->getKey())->where('status', 'pending')
                ->update(['status' => 'approved', 'decided_by' => mb_substr($by, 0, 190), 'decided_at' => now(), 'updated_at' => now()]);
            if ($claimed === 0) {
                throw new DomainException('Este pedido já foi decidido.');
            }
            $fresh = AssistantPackageOrder::query()->findOrFail($order->getKey());
            $this->wallet->addPurchase(Tenant::query()->findOrFail($fresh->tenant_id), $fresh->tokens, now()->addMonths($fresh->validity_months), 'Pedido de pacote "'.$fresh->name.'" liberado por '.$by);
        });
    }

    public function reject(AssistantPackageOrder $order, string $by, ?string $note): void
    {
        $claimed = AssistantPackageOrder::query()->whereKey($order->getKey())->where('status', 'pending')
            ->update(['status' => 'rejected', 'decided_by' => mb_substr($by, 0, 190), 'decided_at' => now(), 'note' => $note !== null ? mb_substr(trim($note), 0, 190) : null, 'updated_at' => now()]);
        if ($claimed === 0) {
            throw new DomainException('Este pedido já foi decidido.');
        }
    }

    /** A empresa cancela o pedido dela enquanto está pendente. */
    public function cancel(AssistantPackageOrder $order, User $user): void
    {
        $claimed = AssistantPackageOrder::query()->whereKey($order->getKey())->where('tenant_id', $user->tenant_id)->where('status', 'pending')
            ->update(['status' => 'canceled', 'decided_by' => mb_substr($user->email, 0, 190), 'decided_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            throw new DomainException('Este pedido não está mais aguardando liberação.');
        }
    }
}
