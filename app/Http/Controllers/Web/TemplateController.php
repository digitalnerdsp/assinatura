<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Templates\TemplateFiller;
use App\Templates\ValueDoesNotFit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/** Modelos em PDF com lacunas: o cliente sobe o PDF uma vez, marca as lacunas e a cada uso só preenche os valores. */
final class TemplateController extends Controller
{
    public function index(Request $request): View
    {
        return view('templates.index', [
            'templates' => DocumentTemplate::query()->where('tenant_id', $request->user()->tenant_id)->withCount('fields')->latest()->get(),
        ]);
    }

    public function create(): View
    {
        return view('templates.form', ['template' => new DocumentTemplate()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'pdf' => $this->pdfRules()], $this->messages());

        try {
            $template = $this->templates()->create($request->user()->tenant, $data['name'], (string) file_get_contents($request->file('pdf')->getRealPath()), $request->file('pdf')->getClientOriginalName());
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['pdf' => $e->getMessage()]);
        }

        return redirect()->route('templates.edit', $template)->with('status', 'Modelo criado. Agora marque no documento onde ficam as lacunas.');
    }

    public function edit(Request $request, DocumentTemplate $template): View
    {
        $this->owned($request, $template);

        return view('templates.form', ['template' => $template, 'fields' => $template->fields()->get()]);
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->owned($request, $template);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'fields' => ['nullable', 'string', 'max:100000']], $this->messages());

        $fields = json_decode((string) ($data['fields'] ?? '[]'), true);
        if (! is_array($fields) || ! array_is_list($fields)) {
            return back()->withInput()->withErrors(['fields' => 'Não foi possível ler as lacunas. Recarregue a página e tente de novo.']);
        }

        try {
            $this->templates()->syncFields($template, $fields);
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['fields' => $e->getMessage()]);
        }
        $template->update(['name' => $data['name']]);

        return back()->with('status', 'Modelo salvo.');
    }

    /** Troca o PDF: as lacunas já marcadas são apagadas, então exige confirmação quando existem. */
    public function replacePdf(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->owned($request, $template);
        $request->validate(['pdf' => $this->pdfRules()], $this->messages());

        if ($template->fields()->exists() && ! $request->boolean('confirm')) {
            return back()->withErrors(['confirm' => 'Trocar o PDF apaga as lacunas já marcadas. Confirme para continuar.']);
        }

        try {
            $this->templates()->replacePdf($template, (string) file_get_contents($request->file('pdf')->getRealPath()), $request->file('pdf')->getClientOriginalName());
        } catch (FlowException $e) {
            return back()->withErrors(['pdf' => $e->getMessage()]);
        }

        return redirect()->route('templates.edit', $template)->with('status', 'PDF trocado. Marque as lacunas de novo.');
    }

    /** O PDF do modelo, para o visualizador da tela de edição. */
    public function pdf(Request $request, DocumentTemplate $template): Response
    {
        $this->owned($request, $template);

        return response($template->pdfContents(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="modelo.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->owned($request, $template);
        $this->templates()->delete($template);

        return redirect()->route('templates.index')->with('status', 'Modelo removido.');
    }
    /** Formulário de uso: um campo para cada lacuna, na ordem de leitura. */
    public function use(Request $request, DocumentTemplate $template): View
    {
        $this->owned($request, $template);

        return view('templates.use', [
            'template' => $template,
            'fields' => $template->fields()->get(),
            'folderPaths' => app(\App\Folders\FolderService::class)->paths($request->user()->tenant),
        ]);
    }

    /** Prévia do PDF preenchido (aceita lacunas em branco). Não cria nada. */
    public function preview(Request $request, DocumentTemplate $template): Response
    {
        $this->owned($request, $template);
        $template->load('fields');
        $data = $request->validate(['values' => ['nullable', 'array']] + $this->filler()->rules($template, enforceRequired: false), $this->valueMessages());

        try {
            $pdf = $this->filler()->render($template, $data['values'] ?? []);
        } catch (ValueDoesNotFit $e) {
            abort(422, $e->getMessage());
        } catch (FlowException $e) {
            abort(422, $e->getMessage());
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="previa.pdf"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** Escreve os valores no PDF, cria o rascunho (passo 1 concluído, com o modelo de origem) e leva ao passo 2 do assistente. */
    public function generate(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->owned($request, $template);
        $template->load('fields');
        if ($template->fields->isEmpty()) {
            return back()->withErrors(['values' => 'Marque ao menos uma lacuna no modelo antes de usá-lo.']);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'folder_id' => ['nullable', 'uuid'],
            'values' => ['nullable', 'array'],
        ] + $this->filler()->rules($template), $this->valueMessages());

        try {
            $pdf = $this->filler()->render($template, $data['values'] ?? []);
            $envelope = app(EnvelopeService::class)->createDraft(
                $request->user()->tenant, $request->user(), $pdf, Str::slug($template->name).'.pdf', $data['title'], $data['folder_id'] ?? null,
            );
        } catch (ValueDoesNotFit $e) {
            return back()->withInput()->withErrors(["values.{$e->fieldKey}" => $e->getMessage()]);
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->withErrors(['title' => 'Não foi possível gerar o documento a partir deste modelo.']);
        }

        $envelope->update(['template_id' => $template->getKey()]);

        return redirect()->route('envelopes.step', [$envelope, 2])->with('status', 'Documento preenchido. Agora escolha quem assina.');
    }
    private function filler(): TemplateFiller
    {
        return app(TemplateFiller::class);
    }

    /** @return array<string,string> */
    private function valueMessages(): array
    {
        return ['values.*.required' => 'Preencha este campo.', 'values.*.max' => 'Use no máximo '.TemplateFiller::MAX_VALUE.' caracteres.', 'title.required' => 'Dê um título ao documento.'];
    }

    /** Resolvido a cada uso (e não no construtor): o serviço depende do cliente do selador. */
    private function templates(): \App\Templates\TemplateService
    {
        return app(\App\Templates\TemplateService::class);
    }

    /** @return list<mixed> */
    private function pdfRules(): array
    {
        return ['required', 'file', 'mimes:pdf', 'max:'.((int) config('signing.max_pdf_mb') * 1024)];
    }

    /** @return array<string,string> */
    private function messages(): array
    {
        return [
            'name.required' => 'Informe o nome do modelo.',
            'pdf.required' => 'Envie o PDF do modelo.',
            'pdf.mimes' => 'Envie um arquivo PDF.',
            'pdf.file' => 'Envie um arquivo PDF.',
            'pdf.max' => 'O PDF excede o tamanho máximo permitido.',
            'pdf.uploaded' => 'Não foi possível enviar o arquivo. Ele pode ser maior que o permitido.',
        ];
    }
    private function owned(Request $request, DocumentTemplate $template): void
    {
        abort_unless($template->tenant_id === $request->user()->tenant_id, 404);
    }
}
