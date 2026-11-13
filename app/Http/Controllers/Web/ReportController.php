<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Contacts\ContactCsv;
use App\Folders\FolderService;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\Envelope;
use App\Models\Tag;
use App\Reports\ReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Relatórios dos documentos da empresa. */
final class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $tenant = $request->user()->tenant;

        return view('reports.index', [
            'filters' => $filters,
            'summary' => $this->reports->summary($tenant, $filters),
            'rows' => $this->reports->rows($tenant, $filters)->paginate(25)->withQueryString(),
            'folderPaths' => app(FolderService::class)->paths($tenant),
            'tags' => Tag::query()->where('tenant_id', $tenant->getKey())->orderBy('name')->get(),
            'templates' => DocumentTemplate::query()->where('tenant_id', $tenant->getKey())->orderBy('name')->get(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $tenant = $request->user()->tenant;
        $paths = app(FolderService::class)->paths($tenant);

        return response()->streamDownload(function () use ($tenant, $filters, $paths): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fwrite($out, "Título;Situação;Criado em;Enviado em;Concluído em;Tempo até concluir;Pasta;Etiquetas;Signatários\n");
            $fmt = fn ($d) => $d?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '';
            $this->reports->rows($tenant, $filters)->chunk(500, function ($chunk) use ($out, $fmt, $paths): void {
                foreach ($chunk as $e) {
                    /** @var Envelope $e */
                    $secs = $e->sent_at && $e->completed_at ? $e->completed_at->getTimestamp() - $e->sent_at->getTimestamp() : null;
                    fputcsv($out, array_map([ContactCsv::class, 'safeCell'], [
                        $e->title, __('status.'.$e->status->value), $fmt($e->created_at), $fmt($e->sent_at), $fmt($e->completed_at),
                        $secs === null ? '' : ReportService::humanDuration($secs), $paths[$e->folder_id] ?? '',
                        $e->tags->pluck('name')->implode(', '), $e->signers->pluck('email')->implode(', '),
                    ]), ';', '"', '');
                }
            });
            fclose($out);
        }, 'relatorio-documentos.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string,string> */
    private function filters(Request $request): array
    {
        return array_filter($request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'folder' => ['nullable', 'uuid'],
            'tag' => ['nullable', 'uuid'],
            'template' => ['nullable', 'uuid'],
            'signer' => ['nullable', 'email', 'max:190'],
        ]), fn ($v) => $v !== null && $v !== '');
    }
}
