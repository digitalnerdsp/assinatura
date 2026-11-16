<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SenderPolicy;
use App\Folders\FolderService;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Assistente de criação de documento em 4 passos. O rascunho vive no banco; cada passo é uma página. */
final class DocumentWizardController extends Controller
{
    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly FolderService $folders,
    ) {
    }

    /** Passo 1 sem rascunho: ainda não há nada gravado. */
    public function create(Request $request): View
    {
        return view('documents.wizard.step1', $this->data($request, null, 1));
    }

    /** Passo 1: envia o PDF e cria o rascunho. */
    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.((int) config('signing.max_pdf_mb') * 1024)],
            'title' => ['nullable', 'string', 'max:190'],
            'folder_id' => ['nullable', 'uuid'],
        ], ['document.required' => 'Escolha o PDF que será assinado.', 'document.mimes' => 'O arquivo precisa ser um PDF.', 'document.max' => 'O PDF passa do tamanho máximo ('.config('signing.max_pdf_mb').' MB).']);

        $file = $request->file('document');
        try {
            $envelope = $this->envelopes->createDraft(
                $request->user()->tenant,
                $request->user(),
                (string) file_get_contents($file->getRealPath()),
                $file->getClientOriginalName(),
                $this->titleFor($data['title'] ?? null, $file->getClientOriginalName()),
                ($data['folder_id'] ?? null) ?: null,
            );
        } catch (FlowException $e) {
            return back()->withInput()->withErrors([$e->reason === 'invalid_folder' ? 'folder_id' : 'document' => $e->getMessage()]);
        }

        return redirect()->route('envelopes.step', [$envelope, 2]);
    }

    public function show(Request $request, Envelope $envelope, int $n): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, $envelope, $n)) {
            return $redirect;
        }

        $view = "documents.wizard.step{$n}";

        return view(view()->exists($view) ? $view : 'documents.wizard.pending', $this->data($request, $envelope, $n));
    }

    public function save(Request $request, Envelope $envelope, int $n): RedirectResponse
    {
        if ($redirect = $this->guard($request, $envelope, $n)) {
            return $redirect;
        }

        return match ($n) {
            1 => $this->saveStep1($request, $envelope),
            2 => $this->saveStep2($request, $envelope),
            3 => $this->saveStep3($request, $envelope),
            default => abort(404),
        };
    }

    /** Cria uma pasta sem sair do passo 1. */
    public function quickFolder(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'parent_id' => ['nullable', 'uuid']]);
        $tenant = $request->user()->tenant;

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = Folder::query()->where('tenant_id', $tenant->getKey())->find($data['parent_id']);
            if ($parent === null) {
                return response()->json(['message' => 'Pasta inválida.'], 422);
            }
        }

        try {
            $folder = $this->folders->create($tenant, $data['name'], $parent);
        } catch (FlowException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $folder->getKey(), 'path' => $this->folders->paths($tenant)[$folder->getKey()] ?? $folder->name]);
    }

    private function saveStep1(Request $request, Envelope $envelope): RedirectResponse
    {
        $data = $request->validate([
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:'.((int) config('signing.max_pdf_mb') * 1024)],
            'title' => ['nullable', 'string', 'max:190'],
            'folder_id' => ['nullable', 'uuid'],
        ], ['document.mimes' => 'O arquivo precisa ser um PDF.', 'document.max' => 'O PDF passa do tamanho máximo ('.config('signing.max_pdf_mb').' MB).']);

        $folderId = ($data['folder_id'] ?? null) ?: null;
        if ($folderId !== null && ! Folder::query()->where('tenant_id', $envelope->tenant_id)->whereKey($folderId)->exists()) {
            return back()->withInput()->withErrors(['folder_id' => 'Pasta inválida.']);
        }

        try {
            if ($file = $request->file('document')) {
                $this->envelopes->replaceDocument($envelope, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
            }
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['document' => $e->getMessage()]);
        }

        $envelope->update([
            'title' => $this->titleFor($data['title'] ?? null, $envelope->fresh()->document->name),
            'folder_id' => $folderId,
        ]);

        return redirect()->route('envelopes.step', [$envelope, 2]);
    }

    private function saveStep2(Request $request, Envelope $envelope): RedirectResponse
    {
        $user = $request->user();

        // Linhas totalmente em branco (o campo vazio que a tela mostra de partida) são ignoradas.
        $rows = collect((array) $request->input('people', []))
            ->filter(fn ($p) => is_array($p) && trim(($p['name'] ?? '').($p['email'] ?? '').($p['phone'] ?? '').($p['cpf'] ?? '')) !== '')
            ->values()->all();
        $request->merge(['people' => $rows]);

        $data = $request->validate([
            'people' => ['array', 'max:20'],
            'people.*.id' => ['nullable', 'uuid'],
            'people.*.name' => ['required', 'string', 'max:150'],
            'people.*.email' => ['nullable', 'email', 'max:190'],
            'people.*.phone' => ['nullable', 'string', 'max:30'],
            'people.*.cpf' => ['nullable', 'string', 'max:14'],
            'people.*.role' => ['nullable', Rule::in(['signer', 'witness'])],
            'signing_order' => ['nullable', Rule::enum(SigningOrder::class)],
            'sender_signs' => ['nullable', 'boolean'],
            'require_otp' => ['nullable', 'boolean'],
            'require_selfie' => ['nullable', 'boolean'],
            'require_document' => ['nullable', 'boolean'],
            'require_geolocation' => ['nullable', 'boolean'],
        ], [
            'people.max' => 'No máximo 20 signatários por documento.',
            'people.*.name.required' => 'Informe o nome de cada signatário.',
            'people.*.email.email' => 'Esse e-mail não parece válido.',
        ]);

        try {
            $wantsSender = app(SenderPolicy::class)->wantsSenderSignature(
                $user->tenant,
                $request->has('sender_signs') ? $request->boolean('sender_signs') : null,
            );
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['sender' => $e->getMessage()]);
        }
        if ($wantsSender && ! $user->hasSignature()) {
            return back()->withInput()->withErrors(['sender' => 'Crie a sua assinatura em Meu perfil antes de enviar documentos como remetente.']);
        }

        $people = array_map(fn (array $p) => $p + ['role' => 'signer'], $data['people'] ?? []);
        foreach ($people as &$p) {
            $p['role'] = $p['role'] ?: 'signer';
        }
        unset($p);

        try {
            $this->envelopes->syncPeople($envelope, $user, $people, $wantsSender, SigningOrder::from($data['signing_order'] ?? 'parallel'), [
                'otp' => $request->boolean('require_otp'),
                'selfie' => $request->boolean('require_selfie'),
                'document' => $request->boolean('require_document'),
                'geolocation' => $request->boolean('require_geolocation'),
            ]);
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['people' => $e->getMessage()]);
        }

        return redirect()->route('envelopes.step', [$envelope, 3]);
    }

    private function saveStep3(Request $request, Envelope $envelope): RedirectResponse
    {
        $data = $request->validate([
            'skipped' => ['nullable', 'boolean'],
            'fields' => ['nullable', 'array', 'max:60'],
            'fields.*.signer_id' => ['required', 'string', 'max:40'],
            'fields.*.type' => ['required', 'string', 'max:16'],
            'fields.*.page' => ['required', 'integer', 'min:1', 'max:2000'],
            'fields.*.x' => ['required', 'numeric'],
            'fields.*.y' => ['required', 'numeric'],
            'fields.*.width' => ['required', 'numeric'],
            'fields.*.height' => ['required', 'numeric'],
        ], ['fields.max' => 'No máximo 60 campos por documento.']);

        $fields = array_values($data['fields'] ?? []);
        if ($fields === [] && ! $request->boolean('skipped')) {
            return back()->withErrors(['fields' => 'Posicione ao menos uma assinatura ou escolha continuar sem posicionar.']);
        }

        try {
            $this->envelopes->syncFields($envelope, $fields);
        } catch (FlowException $e) {
            return back()->withErrors(['fields' => $e->getMessage()]);
        }

        return redirect()->route('envelopes.step', [$envelope, 4]);
    }

    /** Documento da empresa, rascunho e no passo que já foi alcançado; senão devolve o redirecionamento certo. */
    private function guard(Request $request, Envelope $envelope, ?int $n = null): ?RedirectResponse
    {
        abort_unless($envelope->tenant_id === $request->user()->tenant_id && ! $envelope->isTrashed(), 404);

        if ($envelope->status !== EnvelopeStatus::Draft) {
            return redirect()->route('envelopes.show', $envelope);
        }

        $reached = (int) ($envelope->wizard_step ?? 3);
        if ($n !== null && $n > $reached + 1) {
            return redirect()->route('envelopes.step', [$envelope, $reached + 1]);
        }

        return null;
    }

    private function titleFor(?string $title, string $filename): string
    {
        $title = trim((string) $title);
        if ($title === '') {
            $title = trim(pathinfo($filename, PATHINFO_FILENAME));
        }

        return mb_substr($title !== '' ? $title : 'Documento', 0, 190);
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?Envelope $envelope, int $n): array
    {
        $paths = $this->folders->paths($request->user()->tenant);
        $signers = $envelope ? $envelope->signers()->get() : collect();

        return [
            'senderPolicy' => app(SenderPolicy::class)->policy($request->user()->tenant),
            'senderUser' => $request->user(),
            'people' => $signers->reject(fn ($s) => $s->isSender())->values(),
            'hasSender' => $signers->contains(fn ($s) => $s->isSender()),
            // Passo 3: quem pode receber campo e os campos já salvos (frações da página, origem no canto superior esquerdo).
            'placePeople' => $signers->sortBy('position')->map(fn ($s) => ['id' => (string) $s->getKey(), 'name' => $s->isSender() ? 'Você (remetente)' : $s->name])->values(),
            'placeFields' => $envelope ? $envelope->signatureFields()->get()->map(fn ($f) => [
                'signer_id' => (string) $f->signer_id, 'type' => $f->type, 'page' => (int) $f->page,
                'x' => (float) $f->x, 'y' => (float) $f->y, 'width' => (float) $f->width, 'height' => (float) $f->height,
            ])->values() : collect(),
            'fieldTypes' => \App\Models\SignatureField::TYPES,
            'envelope' => $envelope,
            'step' => $n,
            'reached' => (int) ($envelope?->wizard_step ?? 0),
            'folderPaths' => $paths,
            'folderName' => $envelope?->folder_id ? ($paths[$envelope->folder_id] ?? 'Documentos') : 'Documentos',
        ];
    }
}
