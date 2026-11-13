<?php

declare(strict_types=1);

namespace App\Plans;

use App\Models\Document;
use App\Models\Envelope;
use App\Models\SignerAttachment;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Armazenamento da empresa: PDF original + PDF selado + anexos dos signatários (selfie e documento).
 * Contador incremental em `tenants.storage_bytes`, conciliado pela soma real todas as noites.
 */
final class StorageUsage
{
    public function add(Tenant $tenant, int $bytes): void
    {
        if ($bytes > 0) {
            DB::table('tenants')->where('id', $tenant->getKey())->update(['storage_bytes' => DB::raw('storage_bytes + '.$bytes)]);
        } elseif ($bytes < 0) {
            $this->remove($tenant, -$bytes);
        }
    }

    public function remove(Tenant $tenant, int $bytes): void
    {
        if ($bytes > 0) {
            DB::table('tenants')->where('id', $tenant->getKey())->update(['storage_bytes' => DB::raw('GREATEST(0, storage_bytes - '.$bytes.')')]);
        }
    }

    /** Recalcula pela soma dos tamanhos gravados e corrige o contador. @return int o total em bytes */
    public function reconcile(Tenant $tenant): int
    {
        $id = $tenant->getKey();
        $total = (int) Document::query()->where('tenant_id', $id)->sum('size');

        $total += (int) SignerAttachment::query()
            ->join('signers', 'signers.id', '=', 'signer_attachments.signer_id')
            ->join('envelopes', 'envelopes.id', '=', 'signers.envelope_id')
            ->where('envelopes.tenant_id', $id)
            ->sum('signer_attachments.size');

        Envelope::query()->where('tenant_id', $id)->whereNotNull('final_path')->each(function (Envelope $e) use (&$total) {
            if ($e->final_size === null && Storage::disk('local')->exists($e->final_path)) {
                $e->forceFill(['final_size' => Storage::disk('local')->size($e->final_path)])->save();
            }
            $total += (int) $e->final_size;
        });

        DB::table('tenants')->where('id', $id)->update(['storage_bytes' => $total]);

        return $total;
    }
}
