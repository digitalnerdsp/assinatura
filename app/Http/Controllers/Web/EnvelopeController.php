<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Evidence\EvidenceLogger;
use App\Evidence\RequestContext;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Signer;
use App\Models\Tag;
use App\Notifications\SignerNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EnvelopeController extends Controller
{
    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly SignerNotifier $notifier,
        private readonly EvidenceLogger $evidence,
        private readonly \App\Envelopes\TrashService $trash,
    ) {
    }

    public function index(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(\App\Enums\EnvelopeStatus::class)],
            'folder' => ['nullable', 'string', 'max:40'],
            'tag' => ['nullable', 'uuid'],
            'arquivados' => ['nullable', 'boolean'],
        ]);
        $onlyArchived = $request->boolean('arquivados');

        $envelopes = Envelope::query()
            ->where('tenant_id', $tenantId)
            ->when($onlyArchived, fn ($q) => $q->whereNotNull('archived_at')->whereNull('trashed_at'), fn ($q) => $q->visible())
            ->with(['tags', 'folder'])
            ->withCount('signers')
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
                $query->where(fn ($w) => $w
                    ->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(verification_code) LIKE ?', [$like])
                    ->orWhereHas('signers', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]))
                    ->orWhereHas('document', fn ($d) => $d->whereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['folder'] ?? null, fn ($query, string $folder) => $folder === 'none'
                ? $query->whereNull('folder_id')
                : $query->where('folder_id', $folder))
            ->when($filters['tag'] ?? null, fn ($query, string $tag) => $query->whereHas('tags', fn ($t) => $t->where('tags.id', $tag)))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('envelopes.index', [
            'envelopes' => $envelopes,
            'folders' => Folder::query()->where('tenant_id', $tenantId)->orderBy('name')->get(),
            'tags' => Tag::query()->where('tenant_id', $tenantId)->orderBy('name')->get(),
            'filters' => $filters,
            'onlyArchived' => $onlyArchived,
        ]);
    }

    public function trashIndex(Request $request): View
    {
        $items = Envelope::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereNotNull('trashed_at')
            ->latest('trashed_at')
            ->paginate(20);

        return view('documents.trash', ['items' => $items, 'days' => 30]);
    }

    public function trash(Request $request, Envelope $envelope): RedirectResponse
    {
        return $this->transition($request, $envelope, fn () => $this->trash->trash($envelope), 'Movido para a lixeira.');
    }

    public function restore(Request $request, Envelope $envelope): RedirectResponse
    {
        return $this->transition($request, $envelope, fn () => $this->trash->restore($envelope), 'Documento restaurado.');
    }

    public function archive(Request $request, Envelope $envelope): RedirectResponse
    {
        return $this->transition($request, $envelope, fn () => $this->trash->archive($envelope), 'Documento arquivado.');
    }

    public function unarchive(Request $request, Envelope $envelope): RedirectResponse
    {
        return $this->transition($request, $envelope, fn () => $this->trash->unarchive($envelope), 'Documento desarquivado.');
    }

    private function transition(Request $request, Envelope $envelope, callable $action, string $message): RedirectResponse
    {
        $this->owned($request, $envelope);

        try {
            $action();
        } catch (FlowException $e) {
            return back()->withErrors(['envelope' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }

    public function create(Request $request): View
    {
        return view('envelopes.create', $this->formData($request));
    }

    /** @return array<string,mixed> */
    private function formData(Request $request): array
    {
        $tenantId = $request->user()->tenant_id;

        return [
            'folders' => Folder::query()->where('tenant_id', $tenantId)->orderBy('name')->get(),
            'tags' => Tag::query()->where('tenant_id', $tenantId)->orderBy('name')->get(),
        ];
    }

    /** Organização do envelope: pasta e etiquetas. */
    public function organize(Request $request, Envelope $envelope): RedirectResponse
    {
        $this->owned($request, $envelope);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'folder_id' => ['nullable', 'uuid'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['uuid'],
        ]);

        $folderId = $data['folder_id'] ?? null;
        if ($folderId !== null && ! Folder::query()->where('tenant_id', $tenantId)->whereKey($folderId)->exists()) {
            return back()->withErrors(['envelope' => 'Pasta inválida.']);
        }

        $envelope->update(['folder_id' => $folderId]);
        $envelope->tags()->sync(Tag::query()->where('tenant_id', $tenantId)->whereIn('id', $data['tags'] ?? [])->pluck('id')->all());

        return back()->with('status', 'Organização atualizada.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.((int) config('signing.max_pdf_mb') * 1024)],
            'signing_order' => ['required', Rule::enum(SigningOrder::class)],
            'validity_days' => ['nullable', 'integer', 'between:1,365'],
            'reminder_every_days' => ['nullable', 'string', 'in:off,1,2,3,5,7,10,14'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,whatsapp,sms'],
            'folder_id' => ['nullable', 'uuid'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['uuid'],
            'signature_fields' => ['nullable', 'array', 'max:60'],
            'signature_fields.*.signer' => ['required', 'integer', 'min:0', 'max:19'],
            'signature_fields.*.page' => ['required', 'integer', 'min:1', 'max:2000'],
            'signature_fields.*.x' => ['required', 'numeric', 'between:0,1'],
            'signature_fields.*.y' => ['required', 'numeric', 'between:0,1'],
            'require_otp' => ['nullable', 'boolean'],
            'require_selfie' => ['nullable', 'boolean'],
            'require_document' => ['nullable', 'boolean'],
            'require_geolocation' => ['nullable', 'boolean'],
            'signers' => ['required', 'array', 'min:1', 'max:20'],
            'signers.*.name' => ['required', 'string', 'max:150'],
            'signers.*.email' => ['required', 'email', 'max:190'],
            'signers.*.phone' => ['nullable', 'string', 'max:30'],
            'signers.*.cpf' => ['nullable', 'string', 'max:14'],
            'witnesses' => ['nullable', 'array', 'max:'.(int) config('signing.max_witnesses')],
            'witnesses.*.name' => ['required', 'string', 'max:150'],
            'witnesses.*.email' => ['required', 'email', 'max:190'],
            'witnesses.*.phone' => ['nullable', 'string', 'max:30'],
            'witnesses.*.cpf' => ['nullable', 'string', 'max:14'],
        ]);

        // Signatários primeiro, testemunhas depois: os índices de signature_fields seguem essa ordem.
        $people = array_merge(
            array_values($data['signers']),
            array_map(fn ($w) => $w + ['role' => 'witness'], array_values($data['witnesses'] ?? [])),
        );

        try {
            $envelope = $this->envelopes->create(
                $request->user()->tenant,
                $request->user(),
                (string) file_get_contents($request->file('document')->getRealPath()),
                $request->file('document')->getClientOriginalName(),
                $data['title'],
                $people,
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
                    'signature_fields' => array_values($data['signature_fields'] ?? []),
                ],
            );
        } catch (FlowException $e) {
            return back()->withInput()->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()->route('envelopes.show', $envelope)->with('status', 'Envelope criado. Revise e envie para assinatura.');
    }

    public function show(Request $request, Envelope $envelope): View
    {
        $this->owned($request, $envelope);
        $envelope->load(['signers.signature', 'signers.attachments', 'document', 'tags', 'folder', 'signatureFields']);
        $tenantId = $request->user()->tenant_id;

        return view('envelopes.show', [
            'folders' => Folder::query()->where('tenant_id', $tenantId)->orderBy('name')->get(),
            'allTags' => Tag::query()->where('tenant_id', $tenantId)->orderBy('name')->get(),
            'activeIds' => $envelope->status->isOpenForSigning() ? $envelope->activeSigners()->pluck('id')->all() : [],
            'envelope' => $envelope,
            'events' => $envelope->evidenceEvents()->get(),
            'intact' => $this->evidence->isIntact($envelope),
        ]);
    }

    public function send(Request $request, Envelope $envelope): RedirectResponse
    {
        $this->owned($request, $envelope);

        try {
            $tokens = $this->envelopes->send($envelope, RequestContext::fromRequest($request));
        } catch (FlowException $e) {
            return back()->withErrors(['envelope' => $e->getMessage()]);
        }

        if ($request->boolean('skip_email')) {
            // Envio manual: o sistema não avisa ninguém sozinho (nem o próximo da fila, nem lembretes).
            $envelope->update(['notify_channels' => ['manual'], 'reminder_every_days' => null]);

            return back()->with('status', 'Envelope liberado para assinatura. Copie o link de cada signatário abaixo e envie como preferir.');
        }

        // Paralelo: todos; sequencial: só o primeiro grupo (os demais são avisados na sua vez).
        $failed = $this->notifier->inviteActive($envelope->fresh());

        if ($failed !== []) {
            return back()->withErrors([
                'envelope' => 'O envelope foi liberado, mas o aviso não pôde ser enviado para: '.implode(', ', $failed)
                    .'. Copie o link de cada signatário abaixo e envie manualmente (ou confira e-mail/telefone e a configuração de envio com o administrador).',
            ]);
        }

        return back()->with('status', $envelope->signing_order === SigningOrder::Sequential
            ? 'Convite enviado ao primeiro signatário. Os próximos serão avisados na vez deles.'
            : 'Convites enviados.');
    }

    /** Reenfileira a selagem de um envelope preso em "Selando" (ex.: serviço de selagem estava fora do ar). */
    public function reseal(Request $request, Envelope $envelope): RedirectResponse
    {
        $this->owned($request, $envelope);

        if ($envelope->status !== \App\Enums\EnvelopeStatus::Sealing) {
            return back()->withErrors(['envelope' => 'Este envelope não está aguardando selagem.']);
        }

        \App\Jobs\SealEnvelope::dispatch($envelope->getKey());

        return back()->with('status', 'Selagem reenfileirada. Atualize a página em alguns segundos.');
    }

    public function resend(Request $request, Envelope $envelope, Signer $signer): RedirectResponse
    {
        $this->owned($request, $envelope);
        abort_unless($signer->envelope_id === $envelope->getKey(), 404);

        $url = $signer->signUrl();
        if (! $envelope->status->isOpenForSigning() || $url === null) {
            return back()->withErrors(['envelope' => 'Não há link ativo para reenviar. Gere um novo link.']);
        }

        if (! $envelope->activeSigners()->contains(fn (Signer $s) => $s->getKey() === $signer->getKey())) {
            return back()->withErrors(['envelope' => 'Este signatário ainda não está na vez de assinar (ou já respondeu).']);
        }

        $token = basename($url);

        return $this->notifier->invite($signer, $token)
            ? back()->with('status', 'Aviso reenviado para '.$signer->name.'.')
            : back()->withErrors(['envelope' => 'Não foi possível enviar o aviso. Copie o link e envie manualmente.']);
    }

    public function regenerate(Request $request, Envelope $envelope, Signer $signer): RedirectResponse
    {
        $this->owned($request, $envelope);
        abort_unless($signer->envelope_id === $envelope->getKey(), 404);

        try {
            $this->envelopes->regenerateLink($signer, RequestContext::fromRequest($request));
        } catch (FlowException $e) {
            return back()->withErrors(['envelope' => $e->getMessage()]);
        }

        return back()->with('status', 'Novo link gerado para '.$signer->name.'. O link anterior deixou de funcionar.');
    }

    public function cancel(Request $request, Envelope $envelope): RedirectResponse
    {
        $this->owned($request, $envelope);

        try {
            $this->envelopes->cancel($envelope, RequestContext::fromRequest($request));
        } catch (FlowException $e) {
            return back()->withErrors(['envelope' => $e->getMessage()]);
        }

        return back()->with('status', 'Envelope cancelado.');
    }

    public function original(Request $request, Envelope $envelope): StreamedResponse
    {
        $this->owned($request, $envelope);

        return Storage::disk('local')->download($envelope->document->storage_path, $envelope->document->name);
    }

    public function final(Request $request, Envelope $envelope): StreamedResponse
    {
        $this->owned($request, $envelope);
        abort_if($envelope->final_path === null, 404);

        return Storage::disk('local')->download($envelope->final_path, 'assinado-'.$envelope->document->name);
    }

    public function attachment(Request $request, Envelope $envelope, \App\Models\SignerAttachment $attachment): \Illuminate\Http\Response
    {
        $this->owned($request, $envelope);
        abort_unless($attachment->signer->envelope_id === $envelope->getKey(), 404);

        return response(Storage::disk('local')->get($attachment->path), 200, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline',
        ]);
    }

    private function owned(Request $request, Envelope $envelope): void
    {
        abort_unless($envelope->tenant_id === $request->user()->tenant_id, 404);
    }
}
