<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SigningService;
use App\Evidence\RequestContext;
use App\Http\Controllers\Controller;
use App\Models\Signer;
use App\Notifications\SignerNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Página do signatário. O token da URL é o único segredo: tratá-lo como credencial. */
final class SigningController extends Controller
{
    public function __construct(
        private readonly SigningService $signing,
        private readonly SignerNotifier $notifier,
    ) {
    }

    public function show(Request $request, string $token): View
    {
        $signer = $this->signer($token);
        if ($signer instanceof View) {
            return $signer;
        }

        $this->signing->view($signer, RequestContext::fromRequest($request));

        $envelope = $signer->envelope;
        $pending = $this->signing->pendingSteps($signer);

        // Etapas na ordem de exibição, cada uma com o estado (feita / atual / aguardando).
        $labels = [
            'otp' => 'Código por e-mail',
            'selfie' => 'Selfie',
            'document' => 'Foto do documento',
            'geolocation' => 'Localização',
        ];
        $steps = [];
        foreach ($labels as $key => $label) {
            if ($envelope->{'require_'.$key}) {
                $steps[] = ['key' => $key, 'label' => $label, 'done' => ! in_array($key, $pending, true)];
            }
        }
        $steps[] = ['key' => 'sign', 'label' => 'Assinar', 'done' => false];

        return view('sign.show', [
            'signer' => $signer,
            'envelope' => $envelope,
            'token' => $token,
            'steps' => $steps,
            'current' => $pending[0] ?? 'sign',
        ]);
    }

    public function selfie(Request $request, string $token): RedirectResponse|View
    {
        $data = $request->validate([
            'image' => ['required', 'string', 'max:8000000', 'starts_with:data:image/'],
            'source' => ['nullable', 'in:camera,upload'],
        ]);

        return $this->guard($token, function (Signer $signer) use ($request, $token, $data) {
            $binary = base64_decode(substr($data['image'], (int) strpos($data['image'], ',') + 1), true);
            if ($binary === false) {
                throw FlowException::because('invalid_image', 'Imagem inválida.');
            }

            $this->signing->submitSelfie($signer, $binary, $data['source'] ?? 'camera', RequestContext::fromRequest($request));

            return redirect()->route('sign.show', $token)->with('status', 'Selfie registrada.');
        });
    }

    public function document(Request $request, string $token): RedirectResponse|View
    {
        $request->validate(['image' => ['required', 'file', 'image', 'max:5120']]);

        return $this->guard($token, function (Signer $signer) use ($request, $token) {
            $this->signing->submitDocument(
                $signer,
                (string) file_get_contents($request->file('image')->getRealPath()),
                'upload',
                RequestContext::fromRequest($request),
            );

            return redirect()->route('sign.show', $token)->with('status', 'Foto do documento recebida.');
        });
    }

    public function geolocation(Request $request, string $token): RedirectResponse|View
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->guard($token, function (Signer $signer) use ($request, $token, $data) {
            $this->signing->recordGeolocation(
                $signer,
                (float) $data['lat'],
                (float) $data['lng'],
                isset($data['accuracy']) ? (float) $data['accuracy'] : null,
                RequestContext::fromRequest($request),
            );

            return redirect()->route('sign.show', $token)->with('status', 'Localização registrada.');
        });
    }

    public function pdf(string $token): Response
    {
        $signer = Signer::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($signer->envelope->status->isOpenForSigning() || $signer->envelope->status->value === 'completed', 404);

        return response(Storage::disk('local')->get($signer->envelope->document->storage_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="documento.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function requestOtp(Request $request, string $token): RedirectResponse
    {
        return $this->guard($token, function (Signer $signer) use ($request, $token) {
            $code = $this->signing->requestOtp($signer, 'email', RequestContext::fromRequest($request));
            if (! $this->notifier->otp($signer, $code)) {
                return redirect()->route('sign.show', $token)->withErrors([
                    'flow' => 'Não foi possível enviar o código por e-mail agora. Avise quem enviou o documento.',
                ]);
            }

            return redirect()->route('sign.show', $token)->with('status', 'Enviamos um código para '.$this->mask($signer->email).'.');
        });
    }

    public function verifyOtp(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        return $this->guard($token, function (Signer $signer) use ($request, $token, $data) {
            $this->signing->verifyOtp($signer, $data['code'], RequestContext::fromRequest($request));

            return redirect()->route('sign.show', $token)->with('status', 'Código confirmado. Agora você pode assinar.');
        });
    }

    public function sign(Request $request, string $token): RedirectResponse|View
    {
        $data = $request->validate([
            'type' => ['required', 'in:drawn,typed'],
            'typed' => ['nullable', 'string', 'max:150', 'required_if:type,typed'],
            'drawn' => ['nullable', 'string', 'max:300000', 'starts_with:data:image/png;base64,', 'required_if:type,drawn'],
            'terms' => ['accepted'],
        ], ['terms.accepted' => 'É necessário aceitar os termos para assinar.']);

        return $this->guard($token, function (Signer $signer) use ($request, $data) {
            $this->signing->sign(
                $signer,
                $data['type'],
                $data['type'] === 'typed' ? $data['typed'] : $data['drawn'],
                true,
                RequestContext::fromRequest($request),
            );

            return view('sign.done', ['signer' => $signer, 'refused' => false]);
        });
    }

    public function refuse(Request $request, string $token): RedirectResponse|View
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->guard($token, function (Signer $signer) use ($request, $data) {
            $this->signing->refuse($signer, $data['reason'], RequestContext::fromRequest($request));

            return view('sign.done', ['signer' => $signer, 'refused' => true]);
        });
    }

    private function signer(string $token): Signer|View
    {
        try {
            return $this->signing->findByToken($token);
        } catch (FlowException $e) {
            return view('sign.error', ['message' => $e->getMessage()]);
        }
    }

    /** @param callable(Signer):(RedirectResponse|View) $action */
    private function guard(string $token, callable $action): RedirectResponse|View
    {
        $signer = $this->signer($token);
        if ($signer instanceof View) {
            return $signer;
        }

        try {
            return $action($signer);
        } catch (FlowException $e) {
            return redirect()->route('sign.show', $token)->withErrors(['flow' => $e->getMessage()]);
        }
    }

    private function mask(string $email): string
    {
        [$user, $domain] = explode('@', $email, 2);

        return substr($user, 0, 2).str_repeat('*', max(1, strlen($user) - 2)).'@'.$domain;
    }
}
