<?php

declare(strict_types=1);

namespace App\Themes;

/** Dados de exemplo (contrato v1) usados para testar se um tema abre antes de ativá-lo. */
final class ThemeData
{
    /** @return array<string,mixed> */
    public static function sample(): array
    {
        return [
            'tenant' => null,
            'kpi' => ['month' => 0, 'monthDelta' => 0, 'pending' => 0, 'completedMonth' => 0, 'completedDelta' => 0, 'failed' => 0, 'total' => 0],
            'labels' => [],
            'created' => [],
            'completed' => [],
            'statusChart' => collect(),
            'pending' => collect(),
            'recent' => collect(),
            'usedThisMonth' => 0,
        ];
    }
}
