<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Signing\Exceptions\SigningException;
use App\Signing\SigningManager;
use App\Signing\Support\SealerClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

/** Diagnóstico do serviço de selagem (PAdES): conexão, token e certificado da plataforma. */
final class SealingController extends Controller
{
    public function index(): View
    {
        return view('admin.sealing', [
            'provider' => config('signing.platform_provider'),
            'allowLocalTest' => (bool) config('signing.allow_local_test'),
            'sealerUrl' => config('signing.sealer.url'),
            'hasToken' => (string) config('signing.sealer.token') !== '',
            'result' => session('sealing_result'),
        ]);
    }

    public function test(SealerClient $sealer, SigningManager $manager): RedirectResponse
    {
        $steps = [];

        try {
            $sealer->health();
            $steps[] = ['ok' => true, 'label' => 'Serviço de selagem responde', 'detail' => config('signing.sealer.url')];
        } catch (SigningException $e) {
            return back()->with('sealing_result', [...$steps, ['ok' => false, 'label' => 'Serviço de selagem responde', 'detail' => $e->getMessage()]]);
        }

        try {
            $cert = $manager->platform()->certificate();
            $steps[] = ['ok' => true, 'label' => 'Certificado da plataforma ('.config('signing.platform_provider').')', 'detail' =>
                "Titular: {$cert->subject} · emissor: {$cert->issuer} · válido até {$cert->validTo->format('d/m/Y')}"
                .($cert->icpBrasil ? ' · ICP-Brasil' : ' · SEM validade ICP-Brasil (certificado de teste)')];
        } catch (Throwable $e) {
            $steps[] = ['ok' => false, 'label' => 'Certificado da plataforma ('.config('signing.platform_provider').')', 'detail' => $e->getMessage()];
        }

        return back()->with('sealing_result', $steps);
    }
}
