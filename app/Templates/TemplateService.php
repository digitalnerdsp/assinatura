<?php

declare(strict_types=1);

namespace App\Templates;

use App\Envelopes\Exceptions\FlowException;
use App\Models\DocumentTemplate;
use App\Models\TemplateField;
use App\Models\Tenant;
use App\Plans\Entitlements;
use App\Plans\StorageUsage;
use App\Signing\Exceptions\SigningException;
use App\Signing\Support\SealerClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Modelos em PDF: guarda o arquivo (com cota do plano) e as lacunas marcadas pelo cliente. */
final class TemplateService
{
    public const MAX_FIELDS = 40;

    public const MAX_LABEL = 60;

    public const MIN_WIDTH = 0.02;

    public const MIN_HEIGHT = 0.012;

    public function __construct(private readonly SealerClient $sealer, private readonly StorageUsage $storage)
    {
    }

    public function create(Tenant $tenant, string $name, string $pdf, string $filename): DocumentTemplate
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            throw FlowException::because('invalid_name', 'Informe o nome do modelo (até 150 caracteres).');
        }
        $pages = $this->inspect($tenant, $pdf);
        $path = "tenants/{$tenant->getKey()}/templates/".Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $pdf);

        try {
            return DB::transaction(function () use ($tenant, $name, $pdf, $path, $pages) {
                $template = DocumentTemplate::query()->create([
                    'tenant_id' => $tenant->getKey(), 'name' => $name, 'pdf_path' => $path, 'pdf_sha256' => hash('sha256', $pdf),
                    'pdf_size' => strlen($pdf), 'page_count' => $pages,
                ]);
                $this->storage->add($tenant, strlen($pdf));

                return $template;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    /**
     * Grava as lacunas inteiras de uma vez. Cada item: label, page, x, y, width, height, multiline, required.
     * A chave (usada pela API) nasce do nome, na ordem em que a lista chega; a posição segue a ordem de leitura.
     *
     * @param  list<array<string,mixed>>  $fields
     */
    public function syncFields(DocumentTemplate $template, array $fields): void
    {
        if (count($fields) > self::MAX_FIELDS) {
            throw FlowException::because('too_many_fields', 'Um modelo pode ter no máximo '.self::MAX_FIELDS.' lacunas.');
        }

        $clean = [];
        $keys = [];
        foreach (array_values($fields) as $f) {
            $label = trim((string) ($f['label'] ?? ''));
            if ($label === '') {
                throw FlowException::because('invalid_label', 'Dê um nome a cada lacuna.');
            }
            if (mb_strlen($label) > self::MAX_LABEL) {
                throw FlowException::because('invalid_label', 'O nome da lacuna pode ter no máximo '.self::MAX_LABEL.' caracteres.');
            }
            $page = (int) ($f['page'] ?? 0);
            if ($page < 1 || $page > (int) $template->page_count) {
                throw FlowException::because('invalid_page', "A página {$page} não existe neste PDF.");
            }
            [$x, $y, $w, $h] = [(float) ($f['x'] ?? -1), (float) ($f['y'] ?? -1), (float) ($f['width'] ?? 0), (float) ($f['height'] ?? 0)];
            if ($x < 0 || $y < 0 || $x + $w > 1.0001 || $y + $h > 1.0001) {
                throw FlowException::because('invalid_box', "A lacuna “{$label}” precisa ficar dentro da página.");
            }
            if ($w < self::MIN_WIDTH || $h < self::MIN_HEIGHT) {
                throw FlowException::because('invalid_box', "A lacuna “{$label}” está pequena demais.");
            }

            $clean[] = [
                'key' => $this->uniqueKey($label, $keys), 'label' => $label, 'page' => $page, 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h,
                'multiline' => (bool) ($f['multiline'] ?? false), 'required' => (bool) ($f['required'] ?? true),
            ];
        }

        usort($clean, fn (array $a, array $b) => [$a['page'], round($a['y'], 3), $a['x']] <=> [$b['page'], round($b['y'], 3), $b['x']]);

        DB::transaction(function () use ($template, $clean) {
            $template->fields()->delete();
            foreach ($clean as $i => $f) {
                TemplateField::query()->create($f + ['template_id' => $template->getKey(), 'position' => $i + 1]);
            }
        });
    }

    /** Troca o PDF do modelo: as lacunas antigas deixam de fazer sentido e são apagadas. */
    public function replacePdf(DocumentTemplate $template, string $pdf, string $filename): void
    {
        $tenant = Tenant::query()->findOrFail($template->tenant_id);
        $pages = $this->inspect($tenant, $pdf);
        $oldPath = (string) $template->pdf_path;
        $oldSize = (int) $template->pdf_size;
        $path = "tenants/{$tenant->getKey()}/templates/".Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $pdf);

        try {
            DB::transaction(function () use ($template, $tenant, $pdf, $path, $pages, $oldSize) {
                $template->fields()->delete();
                $template->update(['pdf_path' => $path, 'pdf_sha256' => hash('sha256', $pdf), 'pdf_size' => strlen($pdf), 'page_count' => $pages]);
                $this->storage->add($tenant, strlen($pdf) - $oldSize);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        if ($oldPath !== '') {
            Storage::disk('local')->delete($oldPath);
        }
    }

    public function delete(DocumentTemplate $template): void
    {
        $tenant = Tenant::query()->findOrFail($template->tenant_id);
        $path = (string) $template->pdf_path;
        $size = (int) $template->pdf_size;

        DB::transaction(function () use ($template, $tenant, $size) {
            $template->delete();
            $this->storage->remove($tenant, $size);
        });

        if ($path !== '') {
            Storage::disk('local')->delete($path);
        }
    }

    /** Confere o PDF (formato, tamanho, cota) e devolve o total de páginas, contado pelo serviço de selagem. */
    private function inspect(Tenant $tenant, string $pdf): int
    {
        if (! str_starts_with($pdf, '%PDF-')) {
            throw FlowException::because('invalid_document', 'O arquivo enviado não é um PDF válido.');
        }
        $maxMb = (int) config('signing.max_pdf_mb');
        if (strlen($pdf) > $maxMb * 1024 * 1024) {
            throw FlowException::because('document_too_large', "O PDF excede o limite de {$maxMb} MB.");
        }
        Entitlements::for($tenant)->canUpload(strlen($pdf))->orFail();

        try {
            $pages = $this->sealer->fill($pdf, [])['pages'];
        } catch (FillException) {
            throw FlowException::because('invalid_document', 'Este PDF está protegido por senha ou danificado. Envie outro arquivo.');
        } catch (SigningException) {
            throw FlowException::because('sealer_unavailable', 'O serviço de PDF está indisponível no momento. Tente de novo em instantes.');
        }
        if ($pages < 1) {
            throw FlowException::because('invalid_document', 'Não foi possível ler as páginas deste PDF.');
        }

        return $pages;
    }

    /** @param array<string,true> $taken */
    private function uniqueKey(string $label, array &$taken): string
    {
        $base = Str::slug($label, '_') ?: 'campo';
        $base = mb_substr($base, 0, 54);
        $key = $base;
        for ($i = 2; isset($taken[$key]); $i++) {
            $key = $base.'_'.$i;
        }
        $taken[$key] = true;

        return $key;
    }
}
