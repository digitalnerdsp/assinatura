<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\Folder;
use App\Models\Tag;
use App\Templates\TemplateRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Modelos de documento com {{variáveis}}: o cliente escreve uma vez e gera vários documentos. */
final class TemplateController extends Controller
{
    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly TemplateRenderer $renderer,
    ) {
    }

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
    /** Pré-visualização do PDF com os valores de exemplo (nome da variável entre colchetes). */
    public function preview(Request $request, DocumentTemplate $template)
    {
        $this->owned($request, $template);
        $values = collect($template->variables())->mapWithKeys(fn ($v) => [$v => '['.$v.']'])->all();

        return response($this->renderer->render($template, $values), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="previa.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function use(Request $request, DocumentTemplate $template): View
    {
        $this->owned($request, $template);
        $tenantId = $request->user()->tenant_id;

        return view('templates.use', [
            'template' => $template,
            'folderPaths' => app(\App\Folders\FolderService::class)->paths($request->user()->tenant),
            'tags' => Tag::query()->where('tenant_id', $tenantId)->orderBy('name')->get(),
        ]);
    }

    public function generate(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->owned($request, $template);

        $rules = [
            'title' => ['required', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
            'signing_order' => ['required', Rule::enum(SigningOrder::class)],
            'validity_days' => ['nullable', 'integer', 'between:1,365'],
            'reminder_every_days' => ['nullable', 'string', 'in:off,1,2,3,5,7,10,14'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,whatsapp,sms'],
            'folder_id' => ['nullable', 'uuid'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['uuid'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:150'],
            'signers.*.email' => ['required', 'email', 'max:190'],
            'signers.*.phone' => ['nullable', 'string', 'max:30'],
            'signers.*.cpf' => ['nullable', 'string', 'max:14'],
            'vars' => ['nullable', 'array'],
        ];
        foreach ($template->variables() as $var) {
            $rules["vars.$var"] = ['required', 'string', 'max:500'];
        }
        $data = $request->validate($rules);

        try {
            $pdf = $this->renderer->render($template, $data['vars'] ?? []);
            $envelope = $this->envelopes->create(
                $request->user()->tenant,
                $request->user(),
                $pdf,
                Str::slug($template->name).'.pdf',
                $data['title'],
                array_values($data['signers']),
                SigningOrder::from($data['signing_order']),
                'simple',
                $data['message'] ?? null,
                [
                    'otp' => $request->boolean('require_otp'),
                    'selfie' => $request->boolean('require_selfie'),
                    'document' => $request->boolean('require_document'),
                    'geolocation' => $request->boolean('require_geolocation'),
                ],
                [
                    'validity_days' => $data['validity_days'] ?? null,
                    'reminder_every_days' => ($data['reminder_every_days'] ?? '3') === 'off' ? null : (int) ($data['reminder_every_days'] ?? 3),
                    'channels' => $data['channels'] ?? ['email'],
                    'folder_id' => $data['folder_id'] ?? null,
                    'tags' => $data['tags'] ?? [],
                    'template_id' => $template->getKey(),
                ],
            );
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->withErrors(['title' => 'Não foi possível gerar o PDF deste modelo.']);
        }

        return redirect()->route('envelopes.show', $envelope)->with('status', 'Documento gerado a partir do modelo. Revise e envie para assinatura.');
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
