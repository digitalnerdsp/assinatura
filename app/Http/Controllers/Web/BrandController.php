<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Branding\BrandService;
use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Plans\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Configurações › Marca própria: só o responsável da empresa, e só com o recurso no plano. */
final class BrandController extends Controller
{
    public function __construct(private readonly BrandService $brand)
    {
    }

    public function edit(Request $request): View
    {
        $tenant = $this->owner($request)->tenant;

        return view('brand.edit', [
            'tenant' => $tenant,
            'enabled' => Entitlements::for($tenant)->feature('white_label'),
            'brand' => $this->brand->for($tenant),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $this->owner($request)->tenant;
        abort_unless(Entitlements::for($tenant)->feature('white_label'), 403, 'Seu plano não inclui marca própria.');

        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:60'],
            'brand_primary' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'brand_accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'file', 'max:2048', 'mimetypes:image/png,image/jpeg,image/webp'],
        ], ['brand_primary.regex' => 'Use uma cor no formato #RRGGBB.', 'brand_accent.regex' => 'Use uma cor no formato #RRGGBB.', 'logo.max' => 'O logo tem mais de 2 MB.', 'logo.mimetypes' => 'Envie uma imagem PNG, JPG ou WebP (SVG não é aceito).']);

        try {
            $warnings = $this->brand->save($tenant, $request->only(['brand_name', 'brand_primary', 'brand_accent']), $request->file('logo'));
        } catch (FlowException $e) {
            return back()->withErrors(['logo' => $e->getMessage()])->withInput();
        }

        $redirect = redirect()->route('brand.edit')->with('status', 'Marca atualizada.');

        return $warnings === [] ? $redirect : $redirect->with('warning', implode(' ', $warnings));
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->brand->remove($this->owner($request)->tenant);

        return redirect()->route('brand.edit')->with('status', 'Voltamos à marca padrão da plataforma.');
    }

    /** Logo público (aparece na página de assinatura do signatário). Só quando o plano inclui o recurso. */
    public function logo(Tenant $tenant): StreamedResponse
    {
        $path = $this->brand->publicLogoPath($tenant);
        abort_if($path === null, 404);

        return Storage::disk('local')->response($path, 'logo.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function owner(Request $request): \App\Models\User
    {
        $user = $request->user();
        abort_unless($user->role === 'owner', 403);

        return $user;
    }
}
