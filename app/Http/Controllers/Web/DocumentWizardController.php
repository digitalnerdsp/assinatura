<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SenderPolicy;
use App\Envelopes\SenderSignature;
use App\Evidence\RequestContext;
use App\Notifications\SignerNotifier;
use App\Folders\FolderService;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Assistente de criação de documento em 4 passos. O rascunho vive no banco; cada passo é uma página. */
final class DocumentWizardController extends Controller
{
    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly FolderService $folders,
        private readonly SignerNotifier $notifier,
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
            'document' => ['required', 'file', 'extensions:pdf,docx', 'max:'.((int) config('signing.max_pdf_mb') * 1024)],
            'title' => ['nullable', 'string', 'max:190'],
            'folder_id' => ['nullable', 'uuid'],
        ], $this->documentMessages());

        $file = $request->file('document');
        try {
            $source = app(\App\Documents\DocumentIntake::class)->fromUpload($file);
            $envelope = $this->envelopes->createDraft(
                $request->user()->tenant,
                $request->user(),
                $source['pdf'],
                $source['name'],
                $this->titleFor($data['title'] ?? null, $source['name']),
                ($data['folder_id'] ?? null) ?: null,
            );
            $this->recordConversion($envelope, $source);
        } catch (FlowException $e) {
            return back()->withInput()->withErrors([$e->reason === 'invalid_folder' ? 'folder_id' : 'document' => $e->getMessage()]);
        }

        return redirect()->route('envelopes.step', [$envelope, 2]);
    }

    /** @return array<string,string> */
    private function documentMessages(): array
    {
        return [
            'document.required' => 'Escolha o arquivo que será assinado (PDF ou DOCX).',
            'document.extensions' => 'O arquivo precisa ser um PDF ou um DOCX (Word).',
            'document.max' => 'O arquivo passa do tamanho máximo ('.config('signing.max_pdf_mb').' MB).',
        ];
    }

    /** DOCX convertido: a trilha guarda o hash do original, já que só o PDF fica armazenado. */
    private function recordConversion(Envelope $envelope, array $source): void
    {
        if ($source['source_sha256'] !== null) {
            app(\App\Evidence\EvidenceLogger::class)->record($envelope, 'source_converted', ['original_name' => $source['source_name'], 'original_sha256' => $source['source_sha256']]);
        }
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
            4 => $this->saveStep4($request, $envelope),
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
            'document' => ['nullable', 'file', 'extensions:pdf,docx', 'max:'.((int) config('signing.max_pdf_mb') * 1024)],
            'title' => ['nullable', 'string', 'max:190'],
            'folder_id' => ['nullable', 'uuid'],
        ], $this->documentMessages());

        $folderId = ($data['folder_id'] ?? null) ?: null;
        if ($folderId !== null && ! Folder::query()->where('tenant_id', $envelope->tenant_id)->whereKey($folderId)->exists()) {
            return back()->withInput()->withErrors(['folder_id' => 'Pasta inválida.']);
        }

        try {
            if ($file = $request->file('document')) {
                $source = app(\App\Documents\DocumentIntake::class)->fromUpload($file);
                $this->envelopes->replaceDocument($envelope, $source['pdf'], $source['name']);
                $this->recordConversion($envelope, $source);
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
            'people.*.role' => ['nullable', Rule::in(['signer', 'witness', 'observer'])],
            'signing_order' => ['nullable', Rule::enum(SigningOrder::class)],
            'sender_signs' => ['nullable', 'boolean'],
            'people.*.require_otp' => ['nullable', 'boolean'],
            'people.*.require_selfie' => ['nullable', 'boolean'],
            'people.*.require_document' => ['nullable', 'boolean'],
            'people.*.require_geolocation' => ['nullable', 'boolean'],
        ], [
            'people.max' => 'No máximo 20 signatários por documento.',
            'people.*.name.required' => 'Informe o nome de cada signatário.',
            'people.*.email.email' => 'Esse e-mail não parece válido.',
        ]);

        // Observador recebe a cópia por e-mail: sem e-mail não há como entregar.
        $noMail = [];
        foreach ((array) ($data['people'] ?? []) as $i => $p) {
            if (($p['role'] ?? '') === 'observer' && trim((string) ($p['email'] ?? '')) === '') {
                $noMail["people.{$i}.email"] = 'Informe o e-mail do observador: é para lá que vai a cópia do documento assinado.';
            }
        }
        if ($noMail !== []) {
            return back()->withInput()->withErrors($noMail);
        }

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
            $this->envelopes->syncPeople($envelope, $user, $people, $wantsSender, SigningOrder::from($data['signing_order'] ?? 'parallel'));
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

    /** Passo 4 sem enviar: grava as opções. "Voltar" (go=back) grava sem exigir e-mails e leva ao passo 3. */
    private function saveStep4(Request $request, Envelope $envelope): RedirectResponse
    {
        $back = $request->input('go') === 'back';
        if ($error = $this->applyStep4($request, $envelope, ! $back)) {
            return $error;
        }

        return $back
            ? redirect()->route('envelopes.step', [$envelope, 3])
            : redirect()->route('envelopes.step', [$envelope, 4])->with('status', 'Opções salvas.');
    }

    /** Libera o rascunho: grava as opções, envia (assinando como remetente, se for o caso) e avisa os signatários. */
    public function finish(Request $request, Envelope $envelope): RedirectResponse
    {
        if ($redirect = $this->guard($request, $envelope, 4)) {
            return $redirect;
        }
        if ($error = $this->applyStep4($request, $envelope, true)) {
            return $error;
        }

        $user = $request->user();
        $context = RequestContext::fromRequest($request);
        $auto = $request->boolean('auto');

        try {
            // Trava a linha do documento: dois cliques seguidos enviam e cobram uma vez só.
            DB::transaction(function () use ($envelope, $user, $context, $auto): void {
                $locked = Envelope::query()->whereKey($envelope->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->status !== EnvelopeStatus::Draft) {
                    throw FlowException::because('not_draft', 'Somente rascunhos podem ser enviados.');
                }
                $this->envelopes->assertSendable($locked, $auto);

                if ($locked->signers()->where('is_sender', true)->exists()) {
                    if (! $user->hasSignature()) {
                        throw FlowException::because('no_signature', 'Crie a sua assinatura em Meu perfil antes de liberar: o documento sai assinado por você, como remetente.');
                    }
                    $this->envelopes->sendAndSignAsSender(
                        $locked,
                        new SenderSignature((string) $user->signature_type, (string) $user->signature_data, 'session', $user->getKey()),
                        $context,
                        null,
                        ['name' => $user->name, 'email' => $user->email, 'cpf' => null],
                    );
                } else {
                    $this->envelopes->send($locked, $context);
                }
            });
        } catch (FlowException $e) {
            if ($e->reason === 'not_draft') {
                return redirect()->route('envelopes.show', $envelope);
            }

            return back()->withInput()->withErrors(['options' => $e->getMessage()]);
        }

        $envelope = $envelope->fresh();
        if (! $auto) {
            return redirect()->route('envelopes.show', $envelope)->with('status', 'Documento liberado para assinatura. Copie o link de cada signatário abaixo e envie como preferir.');
        }

        $failed = $this->notifier->inviteActive($envelope);
        if ($failed !== []) {
            return redirect()->route('envelopes.show', $envelope)->withErrors([
                'envelope' => 'O documento foi liberado, mas o aviso não pôde ser enviado para: '.implode(', ', $failed)
                    .'. Copie o link de cada signatário abaixo e envie manualmente (ou confira e-mail/telefone e a configuração de envio com o administrador).',
            ]);
        }

        return redirect()->route('envelopes.show', $envelope)->with('status', $envelope->signing_order === SigningOrder::Sequential
            ? 'Convite enviado ao primeiro signatário. Os próximos serão avisados na vez deles.'
            : 'Convites enviados.');
    }

    /**
     * Valida e grava as opções do passo 4. Devolve um redirecionamento quando algo impede (e-mail faltando, limite...),
     * ou nulo quando gravou.
     */
    private function applyStep4(Request $request, Envelope $envelope, bool $strict): ?RedirectResponse
    {
        $data = $request->validate([
            'auto' => ['nullable', 'boolean'],
            'emails' => ['nullable', 'array', 'max:20'],
            'emails.*' => ['nullable', 'string', 'max:190'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,whatsapp,sms'],
            'message' => ['nullable', 'string', 'max:500'],
            'remind' => ['nullable', 'boolean'],
            'remind_days' => ['nullable', 'integer', Rule::in([1, 2, 3, 5, 7, 10, 14])],
            'limit' => ['nullable', 'boolean'],
            // "Hoje" é o dia em Brasília (o servidor roda em UTC e passaria a virar o dia às 21h).
            'limit_date' => ['nullable', 'date_format:Y-m-d', 'after:'.now('America/Sao_Paulo')->toDateString(), 'before_or_equal:'.now('America/Sao_Paulo')->addDays(365)->toDateString()],
            'allow_refuse' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['uuid'],
            'watch' => ['nullable', 'boolean'],
            'contract_date' => ['nullable', 'string', 'max:10'],
            'contract_alert_days' => ['nullable', 'integer'],
            'contract_emails' => ['nullable', 'array', 'max:10'],
            'contract_emails.*' => ['nullable', 'string', 'max:190'],
        ], [
            'message.max' => 'A mensagem pode ter no máximo 500 caracteres.',
            'limit_date.after' => 'A data limite precisa ser depois de hoje.',
            'limit_date.before_or_equal' => 'A data limite pode ser no máximo daqui a 365 dias.',
            'limit_date.date_format' => 'Informe a data limite no formato correto.',
        ]);

        $auto = $request->boolean('auto');

        // Vigência do contrato: confere antes de gravar qualquer outra opção (no "Voltar" um valor inválido é só ignorado).
        $watch = $request->boolean('watch');
        $term = null;
        if ($watch) {
            try {
                $term = [(string) ($data['contract_date'] ?? ''), (int) ($data['contract_alert_days'] ?? 0), (array) ($data['contract_emails'] ?? [])];
                app(\App\Contracts\ContractWatch::class)->validateTerm(...$term);
            } catch (FlowException $e) {
                if ($strict) {
                    return back()->withInput()->withErrors(['contract' => $e->getMessage()]);
                }
                $term = null;
            }
        }

        $signers = $envelope->signers()->where('is_sender', false)->get();
        $emails = (array) ($data['emails'] ?? []);

        $errors = [];
        foreach ($signers as $s) {
            $given = array_key_exists((string) $s->getKey(), $emails) ? trim((string) $emails[(string) $s->getKey()]) : (string) $s->email;
            if ($given !== '' && ! filter_var($given, FILTER_VALIDATE_EMAIL)) {
                $errors['emails.'.$s->getKey()] = 'Esse e-mail não parece válido.';
            } elseif ($strict && $given === '' && $s->isObserver()) {
                $errors['emails.'.$s->getKey()] = 'Informe o e-mail do observador: é para lá que vai a cópia do documento assinado.';
            } elseif ($strict && $given === '' && ! $s->isObserver() && ($auto || $s->requires('otp'))) {
                $errors['emails.'.$s->getKey()] = $auto
                    ? 'Informe o e-mail para enviar o link automaticamente.'
                    : 'Informe o e-mail: o código de confirmação é enviado por e-mail.';
            }
        }
        if ($errors !== []) {
            return back()->withInput()->withErrors($errors);
        }

        try {
            if ($signers->isNotEmpty() && $emails !== []) {
                $people = $signers->map(fn ($s) => [
                    'id' => (string) $s->getKey(), 'name' => $s->name, 'phone' => $s->phone, 'cpf' => $s->cpf, 'role' => $s->role,
                    'require_otp' => $s->require_otp, 'require_selfie' => $s->require_selfie, 'require_document' => $s->require_document, 'require_geolocation' => $s->require_geolocation,
                    'email' => array_key_exists((string) $s->getKey(), $emails) ? $emails[(string) $s->getKey()] : $s->email,
                ])->all();
                $this->envelopes->syncPeople(
                    $envelope, $request->user(), $people, $envelope->signers()->where('is_sender', true)->exists(), $envelope->signing_order,
                    ['otp' => $envelope->require_otp, 'selfie' => $envelope->require_selfie, 'document' => $envelope->require_document, 'geolocation' => $envelope->require_geolocation],
                );
            }

            $validity = (int) config('signing.default_validity_days');
            $deadline = null;
            if ($request->boolean('limit') && ! empty($data['limit_date'])) {
                // A data limite vale até o fim daquele dia em Brasília.
                $deadline = \Illuminate\Support\Carbon::parse($data['limit_date'].' 23:59:59', 'America/Sao_Paulo')->utc();
                $validity = max(1, min(365, (int) ceil(now()->diffInDays($deadline, true))));
            }
            $this->envelopes->applyDelivery($envelope, [
                'message' => $data['message'] ?? null,
                'allow_refuse' => $request->boolean('allow_refuse'),
                'tags' => $data['tags'] ?? [],
                'channels' => $auto ? ($data['channels'] ?? ['email']) : ['manual'],
                'reminder_every_days' => ($auto && $request->boolean('remind')) ? (int) ($data['remind_days'] ?? 3) : null,
                'validity_days' => $validity,
                'deadline_at' => $deadline,
            ]);

            if ($term !== null) {
                app(\App\Contracts\ContractWatch::class)->setTerm($envelope, ...$term);
            } elseif (! $watch) {
                app(\App\Contracts\ContractWatch::class)->clearTerm($envelope);
            }
        } catch (FlowException $e) {
            if ($e->reason === 'not_draft') {
                return redirect()->route('envelopes.show', $envelope);
            }

            return back()->withInput()->withErrors(['options' => $e->getMessage()]);
        }

        return null;
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
            'placePeople' => $signers->reject(fn ($s) => $s->isObserver())->sortBy('position')->map(fn ($s) => ['id' => (string) $s->getKey(), 'name' => $s->isSender() ? 'Você (remetente)' : $s->name, 'sender' => $s->isSender()])->values(),
            'placeFields' => $envelope ? $envelope->signatureFields()->get()->map(fn ($f) => [
                'signer_id' => (string) $f->signer_id, 'type' => $f->type, 'page' => (int) $f->page,
                'x' => (float) $f->x, 'y' => (float) $f->y, 'width' => (float) $f->width, 'height' => (float) $f->height,
            ])->values() : collect(),
            'fieldTypes' => \App\Models\SignatureField::TYPES,
            // Passo 4: opções de envio já salvas (ou o padrão).
            'sendAuto' => $envelope ? ($envelope->notify_channels ?? ['email']) !== ['manual'] : true,
            'sendChannels' => $envelope && ($envelope->notify_channels ?? ['email']) !== ['manual'] ? ($envelope->notify_channels ?? ['email']) : ['email'],
            'remindOn' => $envelope ? ($envelope->reminder_every_days !== null && ($envelope->notify_channels ?? ['email']) !== ['manual']) : true,
            'remindDays' => $envelope?->reminder_every_days ?? (int) config('signing.default_reminder_days'),
            'limitOn' => $envelope && $envelope->deadline_at !== null,
            'limitDate' => $envelope?->deadline_at ? $envelope->deadline_at->copy()->timezone('America/Sao_Paulo')->toDateString() : now('America/Sao_Paulo')->addDays((int) config('signing.default_validity_days'))->toDateString(),
            // Vigência do contrato (bloco do passo 4)
            'creatorEmail' => $request->user()->email,
            'allTags' => \App\Models\Tag::query()->where('tenant_id', $request->user()->tenant_id)->orderBy('name')->get(),
            'selectedTags' => $envelope ? $envelope->tags()->pluck('tags.id')->all() : [],
            'envelope' => $envelope,
            'step' => $n,
            'reached' => (int) ($envelope?->wizard_step ?? 0),
            'folderPaths' => $paths,
            'folderName' => $envelope?->folder_id ? ($paths[$envelope->folder_id] ?? 'Documentos') : 'Documentos',
        ];
    }
}
