<?php

declare(strict_types=1);

namespace App\Folders;

use App\Envelopes\Exceptions\FlowException;
use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pastas com subpastas: até 4 níveis (primária + 3), nome único por pasta pai, sem ciclos.
 * Pastas nunca vão para a lixeira nem para o arquivo; só são criadas, renomeadas, movidas e apagadas.
 * Ao apagar, o que pode ir para a lixeira vai; o resto sobe para a pasta pai da pasta apagada.
 */
final class FolderService
{
    public const MAX_LEVELS = 4;

    private const TRASHABLE = [EnvelopeStatus::Draft, EnvelopeStatus::Cancelled, EnvelopeStatus::Expired];

    public function create(Tenant $tenant, string $name, ?Folder $parent = null): Folder
    {
        $name = $this->cleanName($name);

        if ($parent !== null) {
            $this->assertSameTenant($tenant->getKey(), $parent);
            if ($this->level($parent) >= self::MAX_LEVELS) {
                throw FlowException::because('folder_too_deep', 'Limite de '.self::MAX_LEVELS.' níveis de pastas atingido.');
            }
        }

        $this->assertNameFree($tenant->getKey(), $parent?->getKey(), $name, null);

        try {
            return Folder::query()->create(['tenant_id' => $tenant->getKey(), 'parent_id' => $parent?->getKey(), 'name' => $name]);
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }
    }

    public function rename(Folder $folder, string $name): void
    {
        $name = $this->cleanName($name);
        $this->assertNameFree($folder->tenant_id, $folder->parent_id, $name, $folder->getKey());

        try {
            $folder->update(['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }
    }

    public function move(Folder $folder, ?Folder $newParent): void
    {
        if ($newParent !== null) {
            $this->assertSameTenant($folder->tenant_id, $newParent);

            if (in_array($newParent->getKey(), $this->descendantIds($folder), true)) {
                throw FlowException::because('folder_cycle', 'Não é possível mover uma pasta para dentro dela mesma ou de uma subpasta dela.');
            }
        }

        $destinationLevel = $newParent === null ? 0 : $this->level($newParent);
        if ($destinationLevel + $this->height($folder) > self::MAX_LEVELS) {
            throw FlowException::because('folder_too_deep', 'Mover aqui passaria do limite de '.self::MAX_LEVELS.' níveis de pastas.');
        }

        $this->assertNameFree($folder->tenant_id, $newParent?->getKey(), $folder->name, $folder->getKey());

        try {
            $folder->update(['parent_id' => $newParent?->getKey()]);
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }
    }

    /** Da pasta primária até a própria pasta (inclusive). */
    public function path(Folder $folder): Collection
    {
        $items = [$folder];
        $current = $folder;

        for ($guard = 0; $current->parent_id !== null && $guard < 10; $guard++) {
            $current = Folder::query()->where('tenant_id', $folder->tenant_id)->find($current->parent_id);
            if ($current === null) {
                break;
            }
            array_unshift($items, $current);
        }

        return collect($items);
    }

    public function level(Folder $folder): int
    {
        return $this->path($folder)->count();
    }

    /** @return list<string> ids da pasta e de todas as descendentes (da mesma empresa). */
    public function descendantIds(Folder $folder): array
    {
        $rows = DB::select(
            'WITH RECURSIVE sub AS (
                SELECT id FROM folders WHERE id = ? AND tenant_id = ?
                UNION ALL
                SELECT f.id FROM folders f JOIN sub s ON f.parent_id = s.id WHERE f.tenant_id = ?
            ) SELECT id FROM sub',
            [$folder->getKey(), $folder->tenant_id, $folder->tenant_id],
        );

        return array_map(fn ($r) => (string) $r->id, $rows);
    }

    /** @return list<array{id:string,name:string,level:int,children:list<array<string,mixed>>}> */
    public function tree(Tenant $tenant): array
    {
        $byParent = Folder::query()->where('tenant_id', $tenant->getKey())->orderByRaw('lower(name)')->get()->groupBy(fn (Folder $f) => $f->parent_id ?? '');

        $build = function (string $parent, int $level) use (&$build, $byParent): array {
            return ($byParent[$parent] ?? collect())->map(fn (Folder $f) => [
                'id' => $f->getKey(),
                'name' => $f->name,
                'level' => $level,
                'children' => $build($f->getKey(), $level + 1),
            ])->values()->all();
        };

        return $build('', 1);
    }

    /** @return array{trash:int,keep:int,folders:int,destination:?string} */
    public function impact(Folder $folder): array
    {
        $ids = $this->descendantIds($folder);
        $live = Envelope::query()->where('tenant_id', $folder->tenant_id)->whereIn('folder_id', $ids)->whereNull('trashed_at');

        return [
            'trash' => (clone $live)->whereIn('status', $this->trashableValues())->count(),
            'keep' => (clone $live)->whereNotIn('status', $this->trashableValues())->count(),
            'folders' => count($ids) - 1,
            'destination' => $folder->parent_id === null ? null : Folder::query()->whereKey($folder->parent_id)->value('name'),
        ];
    }

    /**
     * Apaga a pasta e as subpastas. Rascunhos, cancelados e expirados vão para a lixeira; o resto sobe para a
     * pasta pai da pasta apagada (raiz se for primária). Documentos já na lixeira ficam como estão.
     *
     * @return array{trashed:int,kept:int,folders:int}
     */
    public function delete(Folder $folder): array
    {
        return DB::transaction(function () use ($folder): array {
            $ids = $this->descendantIds($folder);
            $mine = fn () => Envelope::query()->where('tenant_id', $folder->tenant_id)->whereIn('folder_id', $ids);

            $trashed = $mine()->whereNull('trashed_at')->whereIn('status', $this->trashableValues())->update(['trashed_at' => now()]);
            $kept = $mine()->whereNull('trashed_at')->update(['folder_id' => $folder->parent_id]);

            Folder::query()->where('tenant_id', $folder->tenant_id)->whereIn('id', $ids)->delete();

            return ['trashed' => $trashed, 'kept' => $kept, 'folders' => count($ids) - 1];
        });
    }

    /** Altura da subárvore (1 = sem subpastas). */
    private function height(Folder $folder): int
    {
        $row = DB::selectOne(
            'WITH RECURSIVE sub(id, d) AS (
                SELECT id, 1 FROM folders WHERE id = ? AND tenant_id = ?
                UNION ALL
                SELECT f.id, s.d + 1 FROM folders f JOIN sub s ON f.parent_id = s.id WHERE f.tenant_id = ?
            ) SELECT max(d) AS h FROM sub',
            [$folder->getKey(), $folder->tenant_id, $folder->tenant_id],
        );

        return (int) ($row->h ?? 1);
    }

    private function cleanName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw FlowException::because('invalid_name', 'O nome da pasta precisa ter de 1 a 120 caracteres.');
        }

        return $name;
    }

    private function assertNameFree(string $tenantId, ?string $parentId, string $name, ?string $ignoreId): void
    {
        $taken = Folder::query()
            ->where('tenant_id', $tenantId)
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            throw $this->nameTaken();
        }
    }

    private function assertSameTenant(string $tenantId, Folder $other): void
    {
        if ($other->tenant_id !== $tenantId) {
            throw FlowException::because('folder_other_tenant', 'Pasta inválida.');
        }
    }

    private function nameTaken(): FlowException
    {
        return FlowException::because('folder_name_taken', 'Já existe uma pasta com este nome aqui.');
    }

    /** @return list<string> */
    private function trashableValues(): array
    {
        return array_map(fn (EnvelopeStatus $s) => $s->value, self::TRASHABLE);
    }
}
