<?php

declare(strict_types=1);

namespace App\Branding;

use App\Envelopes\Exceptions\FlowException;
use App\Models\Tenant;
use App\Plans\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Marca própria (só logo, cores e nome). Vale quando o plano da empresa inclui o recurso `white_label`;
 * sem o recurso, tudo aparece com a marca da plataforma.
 */
final class BrandService
{
    private const MAX_LOGO_BYTES = 2 * 1024 * 1024;

    private const MAX_PIXELS = 12_000_000;

    private const BOX = [400, 160];

    /** @return array{active:bool,name:string,logo_url:?string,primary:?string,accent:?string} */
    public function for(Tenant $tenant): array
    {
        $default = ['active' => false, 'name' => (string) config('app.name'), 'logo_url' => null, 'primary' => null, 'accent' => null];
        if (! Entitlements::for($tenant)->feature('white_label')) {
            return $default;
        }

        $logo = $tenant->brand_logo_path ? route('brand.logo', ['tenant' => $tenant->getKey(), 'v' => substr(md5($tenant->brand_logo_path), 0, 8)]) : null;
        $active = $tenant->brand_name || $logo || $tenant->brand_primary || $tenant->brand_accent;

        return [
            'active' => (bool) $active,
            'name' => $tenant->brand_name ?: $default['name'],
            'logo_url' => $logo,
            'primary' => $tenant->brand_primary,
            'accent' => $tenant->brand_accent,
        ];
    }

    /** Marca desta requisição: a empresa da página de assinatura, ou a de quem está logado. Calculada uma vez por requisição. */
    public static function forRequest(Request $request): array
    {
        $cached = $request->attributes->get('brand_cache');
        if ($cached !== null) {
            return $cached;
        }

        $tenant = $request->attributes->get('brand_tenant') ?? $request->user()?->tenant;
        $brand = $tenant ? app(self::class)->for($tenant) : ['active' => false, 'name' => (string) config('app.name'), 'logo_url' => null, 'primary' => null, 'accent' => null];
        $request->attributes->set('brand_cache', $brand);

        return $brand;
    }

    /** A página pública de assinatura usa a marca da empresa dona do documento. */
    public static function useTenant(Request $request, Tenant $tenant): void
    {
        $request->attributes->set('brand_tenant', $tenant);
        $request->attributes->remove('brand_cache');
    }

    /** Caminho do logo que pode ser mostrado ao público, ou null (sem logo ou plano sem o recurso). */
    public function publicLogoPath(Tenant $tenant): ?string
    {
        if (! $tenant->brand_logo_path || ! Entitlements::for($tenant)->feature('white_label')) {
            return null;
        }

        return Storage::disk('local')->exists($tenant->brand_logo_path) ? $tenant->brand_logo_path : null;
    }

    /**
     * Salva só o que veio em `$data` (nome, cor principal, cor de destaque; vazio limpa) e o logo, se enviado.
     * Cor sem contraste de 4,5:1 contra o texto/fundo claro é trocada pelo tom mais próximo que passa.
     *
     * @param  array<string,mixed>  $data
     * @return list<string> avisos para mostrar à pessoa
     *
     * @throws FlowException logo inválido
     */
    public function save(Tenant $tenant, array $data, ?UploadedFile $logo): array
    {
        $warnings = [];
        $update = [];

        if (array_key_exists('brand_name', $data)) {
            $name = trim((string) $data['brand_name']);
            $update['brand_name'] = $name === '' ? null : mb_substr($name, 0, 60);
        }
        foreach (['brand_primary' => 'principal (usada nos botões, com texto branco)', 'brand_accent' => 'de destaque (usada em links)'] as $field => $label) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $raw = trim((string) $data[$field]);
            if ($raw === '') {
                $update[$field] = null;

                continue;
            }
            $normalized = Contrast::normalize($raw);
            $fixed = Contrast::nearestAccessible($normalized, '#ffffff');
            if ($fixed !== $normalized) {
                $warnings[] = "A cor {$label} {$normalized} não tem contraste suficiente (mínimo 4,5:1); usamos {$fixed}, o tom mais próximo que passa.";
            }
            $update[$field] = $fixed;
        }

        if ($logo !== null) {
            $update['brand_logo_path'] = $this->storeLogo($tenant, $logo);
        }

        $tenant->forceFill($update)->save();

        return $warnings;
    }

    /** Volta tudo ao padrão da plataforma e apaga o logo. */
    public function remove(Tenant $tenant): void
    {
        if ($tenant->brand_logo_path) {
            Storage::disk('local')->delete($tenant->brand_logo_path);
        }
        $tenant->forceFill(['brand_name' => null, 'brand_logo_path' => null, 'brand_primary' => null, 'brand_accent' => null])->save();
    }

    /** CSS da marca (só cores já validadas, nunca texto livre). */
    public static function css(array $brand): string
    {
        $css = '';
        if ($p = $brand['primary']) {
            $d = Contrast::mix($p, '#000000', 0.15);
            $css .= ".btn-primary,.btn-primary:disabled{background-color:{$p};border-color:{$p};color:#fff}"
                ."a.btn-primary:hover,.btn-primary:hover,.btn-primary:focus,.btn-primary:active{background-color:{$d} !important;border-color:{$d} !important;color:#fff !important}"
                .".badge.bg-primary,.bg-primary{background-color:{$p} !important}"
                .'.brand-badge{background:'.($brand['accent'] ? "linear-gradient(135deg,{$p},{$brand['accent']})" : $p).'}'
                .".steps-wizard li.current{border-color:{$p};box-shadow:inset 0 0 0 1px {$p}}"
                ."html:not(.dark-theme) .btn-outline-primary{color:{$p};border-color:{$p}}"
                ."html:not(.dark-theme) .btn-outline-primary:hover{background-color:{$p};color:#fff}";
        }
        if ($a = $brand['accent']) {
            $css .= "html:not(.dark-theme) .page-content a:not(.btn):not(.dropdown-item):not(.page-link):not(.nav-link),html:not(.dark-theme) main.container a:not(.btn){color:{$a}}";
        }

        return $css;
    }

    /** Recorta para caber em 400x160, regrava como PNG (descarta metadados e conteúdo embutido) e apaga o logo anterior. */
    private function storeLogo(Tenant $tenant, UploadedFile $file): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw FlowException::because('invalid_logo', 'O servidor não tem suporte a imagens (GD). Fale com o suporte.');
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        if (strlen($bytes) > self::MAX_LOGO_BYTES) {
            throw FlowException::because('invalid_logo', 'O logo tem mais de 2 MB.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw FlowException::because('invalid_logo', 'Envie uma imagem PNG, JPG ou WebP (SVG não é aceito).');
        }
        [$w, $h] = $info;
        if ($w < 32 || $h < 32) {
            throw FlowException::because('invalid_logo', 'A imagem é pequena demais (mínimo 32 px de lado).');
        }
        if ($w * $h > self::MAX_PIXELS || $w > 6000 || $h > 6000) {
            throw FlowException::because('invalid_logo', 'A imagem tem pixels demais. Reduza para até 4000 px de lado.');
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw FlowException::because('invalid_logo', 'Não foi possível ler esta imagem.');
        }

        $scale = min(1, self::BOX[0] / $w, self::BOX[1] / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagepng($dst, null, 8);
        $png = (string) ob_get_clean();

        $path = "brand/{$tenant->getKey()}/logo-".substr(sha1($png), 0, 12).'.png';
        Storage::disk('local')->put($path, $png);
        if ($tenant->brand_logo_path && $tenant->brand_logo_path !== $path) {
            Storage::disk('local')->delete($tenant->brand_logo_path);
        }

        return $path;
    }
}
