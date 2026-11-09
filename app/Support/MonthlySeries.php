<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Séries mensais para os gráficos dos dashboards (PostgreSQL). */
final class MonthlySeries
{
    /** @return list<string> ex.: ['jun./26', 'jul./26', ...] do mais antigo ao mês atual */
    public static function labels(int $months = 6): array
    {
        $labels = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $labels[] = Carbon::now()->startOfMonth()->subMonths($i)->locale('pt_BR')->isoFormat('MMM/YY');
        }

        return $labels;
    }

    /**
     * Conta registros por mês de $column (nome de coluna fixo, vindo do código — nunca de entrada do usuário).
     *
     * @return list<int> alinhado a labels()
     */
    public static function counts(Builder $query, string $column, int $months = 6): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $rows = (clone $query)
            ->where($column, '>=', $start)
            ->selectRaw("to_char({$column}, 'YYYY-MM') as m, count(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm');

        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $out[] = (int) ($rows[Carbon::now()->startOfMonth()->subMonths($i)->format('Y-m')] ?? 0);
        }

        return $out;
    }

    /** Variação percentual entre o último e o penúltimo valor (null se não houver base). */
    public static function delta(array $series): ?float
    {
        $n = count($series);
        if ($n < 2 || $series[$n - 2] === 0) {
            return null;
        }

        return round((($series[$n - 1] - $series[$n - 2]) / $series[$n - 2]) * 100, 1);
    }
}
