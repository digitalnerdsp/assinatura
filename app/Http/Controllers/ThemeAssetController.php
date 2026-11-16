<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Theme;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Entrega só arquivos de assets/ de um tema instalado, com extensão permitida. Nunca Blade, JSON ou PHP. */
final class ThemeAssetController extends Controller
{
    private const TYPES = [
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'map' => 'application/json',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
    ];

    public function __invoke(string $slug, string $version, string $path): BinaryFileResponse
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_if(str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0") || ! str_starts_with($path, 'assets/')
            || ! in_array($ext, (array) config('themes.served_ext'), true), 404);

        $theme = Theme::query()->where('slug', $slug)->where('version', $version)->first();
        abort_if($theme === null, 404);

        $base = realpath($theme->directory().'/assets');
        $file = $base ? realpath($base.'/'.substr($path, 7)) : false;
        abort_if($file === false || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR) || ! is_file($file), 404);

        $response = response()->file($file, [
            'Content-Type' => self::TYPES[$ext],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($ext === 'svg') {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
        }

        return $response;
    }
}
