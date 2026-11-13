<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Plans\StorageUsage;
use Illuminate\Console\Command;

final class ReconcileStorage extends Command
{
    protected $signature = 'storage:reconcile';

    protected $description = 'Confere o armazenamento de cada empresa pela soma real dos arquivos gravados e corrige o contador';

    public function handle(StorageUsage $usage): int
    {
        $n = 0;
        Tenant::query()->each(function (Tenant $t) use ($usage, &$n) {
            $before = (int) $t->storage_bytes;
            $after = $usage->reconcile($t);
            if ($before !== $after) {
                $this->line("{$t->name}: {$before} → {$after} bytes");
                $n++;
            }
        });
        $this->info("{$n} empresa(s) corrigida(s).");

        return self::SUCCESS;
    }
}
