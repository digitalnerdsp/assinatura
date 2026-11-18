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

    /** Quem já assinou volta para a tela de conclusão (com a cópia, quando o documento já foi selado). */
    private function signedSigner(string $token): ?Signer
    {
        return Signer::query()->where('token_hash', hash('sha256', $token))
            ->where('status', \App\Enums\SignerStatus::Signed->value)->first();
    }

    public function copy(string $token): View
    {
        $signer = $this->signedSigner($token);
        abort_if($signer === null, 404);
        \App\Branding\BrandService::useTenant(request(), $signer->envelope->tenant);

        return view('sign.done', ['signer' => $signer, 'refused' => false, 'envelope' => $signer->envelope, 'token' => $token]);
    }

    /** Baixa (ou, com ?ver=1, abre) o PDF selado do arquivo `n` (1 = principal). Só existe depois que todos assinaram e o selo foi aplicado. */
    public function copyDownload(Request $request, string $token): Response
    {
        $signer = $this->signedSigner($token);
        $envelope = $signer?->envelope;
        $file = $envelope?->files()->firstWhere('n', max(1, (int) $request->query('n', 1)));
        abort_if($file === null || $file->finalPath === null || ! Storage::disk('local')->exists($file->finalPath), 404);

        $inline = $request->boolean('ver');
        app(\App\Evidence\EvidenceLogger::class)->record($envelope, 'signed_copy_downloaded', ['mode' => $inline ? 'view' : 'download', 'document_n' => $file->n], $signer, RequestContext::fromRequest($request));

        return response(Storage::disk('local')->get($file->finalPath), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="assinado-'.addcslashes(preg_replace('/[^\w.\- ]/u', '_', $file->name), '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Todos os PDFs selados do envio num zip. */
    public function copyZip(Request $request, string $token): Response
    {
        $signer = $this->signedSigner($token);
        $envelope = $signer?->envelope;
        $files = $envelope?->files()->filter(fn ($f) => $f->finalPath !== null && Storage::disk('local')->exists($f->finalPath))->values();
        abort_if($files === null || $files->isEmpty() || $files->count() < $envelope->files()->count(), 404);

        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $used = [];
        foreach ($files as $f) {
            $name = 'assinado-'.preg_replace('/[^\w.\- ]/u', '_', $f->name);
            $used[$name] = ($used[$name] ?? 0) + 1;
            $zip->addFromString($used[$name] > 1 ? $f->n.'-'.$name : $name, Storage::disk('local')->get($f->finalPath));
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        app(\App\Evidence\EvidenceLogger::class)->record($envelope, 'signed_copy_downloaded', ['mode' => 'zip', 'files' => $files->count()], $signer, RequestContext::fromRequest($request));

        return response($bytes, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="documentos-assinados.zip"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function show(Request $request, string $token): View|RedirectResponse
    {
        if ($this->signedSigner($token) !== null) {
            return redirect()->route('sign.copy', $token);
        }

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
            if ($signer->requires($key)) {
                $steps[] = ['key' => $key, 'label' => $label, 'done' => ! in_array($key, $pending, true)];
            }
        }
        $steps[] = ['key' => 'sign', 'label' => 'Assinar', 'done' => false];

        \App\Branding\BrandService::useTenant(request(), $envelope->tenant);

        return view('sign.show', [
            'signer' => $signer,
            'envelope' => $envelope,
            'token' => $token,
            'steps' => $steps,
            'current' => $pending[0] ?? 'sign',
            'waiting' => ! $this->signing->isMyTurn($signer),
            'files' => $envelope->files(),
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

    /** PDF do arquivo `n` do envio (1 = principal), para o signatário ler antes de assinar. */
    public function pdf(Request $request, string $token): Response
    {
        $signer = Signer::query()->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($signer->envelope->status->isOpenForSigning() || $signer->envelope->status->value === 'completed', 404);

        $file = $signer->envelope->files()->firstWhere('n', max(1, (int) $request->query('n', 1)));
        abort_if($file === null, 404);

        return response(Storage::disk('local')->get($file->storagePath), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="documento-'.$file->n.'.pdf"',
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
            'initials_type' => ['nullable', 'in:drawn,typed'],
            'initials_typed' => ['nullable', 'string', 'max:30'],
            'initials_drawn' => ['nullable', 'string', 'max:300000', 'starts_with:data:image/png;base64,'],
            'texts' => ['nullable', 'array', 'max:60'],
            'texts.*' => ['nullable', 'string', 'max:200'],
        ], [
            'terms.accepted' => 'É necessário aceitar os termos para assinar.',
            'initials_drawn.starts_with' => 'O visto desenhado é inválido. Desenhe de novo.',
            'texts.*.max' => 'Cada texto pode ter no máximo 200 caracteres.',
        ]);

        return $this->guard($token, function (Signer $signer) use ($request, $data, $token) {
            $this->signing->sign(
                $signer,
                $data['type'],
                $data['type'] === 'typed' ? $data['typed'] : $data['drawn'],
                true,
                RequestContext::fromRequest($request),
                ! empty($data['initials_type']) ? ['type' => $data['initials_type'], 'data' => (string) ($data['initials_type'] === 'drawn' ? ($data['initials_drawn'] ?? '') : ($data['initials_typed'] ?? ''))] : null,
                array_map('strval', (array) ($data['texts'] ?? [])),
            );

            \App\Branding\BrandService::useTenant(request(), $signer->envelope->tenant);

            return view('sign.done', ['signer' => $signer, 'refused' => false, 'envelope' => $signer->envelope->fresh(), 'token' => $token]);
        });
    }

    public function refuse(Request $request, string $token): RedirectResponse|View
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->guard($token, function (Signer $signer) use ($request, $data) {
            abort_unless($signer->envelope->allow_refuse, 403, 'Este documento não permite recusar a assinatura.');
            $this->signing->refuse($signer, $data['reason'], RequestContext::fromRequest($request));

            \App\Branding\BrandService::useTenant(request(), $signer->envelope->tenant);

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
