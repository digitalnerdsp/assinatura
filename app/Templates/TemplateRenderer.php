<?php

declare(strict_types=1);

namespace App\Templates;

use App\Models\DocumentTemplate;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Gera o PDF de um modelo, trocando {{variaveis}} pelos valores informados (sempre escapados). */
final class TemplateRenderer
{
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><h1><h2><h3><h4><ul><ol><li><table><thead><tbody><tr><th><td><hr><div><span><blockquote>';

    /** Remove tags não permitidas e todos os atributos (sem scripts, imagens remotas ou estilos arbitrários). */
    public static function sanitize(string $html): string
    {
        $html = strip_tags($html, self::ALLOWED_TAGS);

        return (string) preg_replace('/<([a-zA-Z0-9]+)(\s[^>]*)?(\/?)>/', '<$1$3>', $html);
    }

    /** @param array<string,string> $values */
    public function render(DocumentTemplate $template, array $values): string
    {
        $body = self::sanitize($template->body);

        $body = preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            fn (array $m) => htmlspecialchars((string) ($values[$m[1]] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $body,
        );

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'@page{margin:60px 55px}body{font-family:DejaVu Sans,sans-serif;font-size:11px;line-height:1.55;color:#111}'
            .'h1{font-size:18px}h2{font-size:15px}h3{font-size:13px}table{border-collapse:collapse;width:100%}'
            .'td,th{border:1px solid #999;padding:4px 6px;vertical-align:top}'
            .'</style></head><body>'.$body.'</body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);   // sem requisições externas (SSRF)
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('chroot', base_path('storage'));
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
