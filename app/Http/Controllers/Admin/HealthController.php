<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SystemHealth;
use Illuminate\View\View;

/** Painel de saúde: fila, selagem, falhas, backup e uso por empresa. */
final class HealthController extends Controller
{
    public function index(SystemHealth $health): View
    {
        return view('admin.health', [
            'checks' => $health->checks(),
            'metrics' => $health->metrics(),
            'sentry' => (string) config('sentry.dsn') !== '',
        ]);
    }
}
