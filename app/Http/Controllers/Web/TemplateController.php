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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Modelos de documento com {{variáveis}}: o cliente escreve uma vez e gera vários envelopes. */
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
            'templates' => DocumentTemplate::query()->where('tenant_id', $request->user()->tenant_id)->latest()->get(),
        ]);
    }

    public function create(): View
    {
        return view('templates.form', ['template' => new DocumentTemplate(['body' => $this->sample()])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $template = DocumentTemplate::query()->create($this->validated($request) + ['tenant_id' => $request->user()->tenant_id]);

        return redirect()->route('templates.edit', $template)->with('status', 'Modelo criado.');
    }

    public function edit(Request $request, DocumentTemplate $template): View
    {
        $this->owned($request, $template);

        return view('templates.form', compact('template'));
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->owned($request, $template);
        $template->update($this->validated($request));

        return back()->with('status', 'Modelo salvo.');
    }

    public function destroy(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->owned($request, $template);
        $template->delete();

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
                ],
            );
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['title' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->withErrors(['title' => 'Não foi possível gerar o PDF deste modelo.']);
        }

        return redirect()->route('envelopes.show', $envelope)->with('status', 'Envelope gerado a partir do modelo. Revise e envie para assinatura.');
    }

    /** @return array{name:string,body:string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:200000'],
        ]);

        return ['name' => $data['name'], 'body' => TemplateRenderer::sanitize($data['body'])];
    }

    private function sample(): string
    {
        return "<h2>Contrato de prestação de serviços</h2>\n<p>Pelo presente instrumento, <strong>{{contratante}}</strong>, CPF/CNPJ {{documento}}, contrata os serviços descritos abaixo.</p>\n<p>Valor: R$ {{valor}}</p>\n<p>Data: {{data}}</p>";
    }

    private function owned(Request $request, DocumentTemplate $template): void
    {
        abort_unless($template->tenant_id === $request->user()->tenant_id, 404);
    }
}
