<?php

declare(strict_types=1);

namespace App\Envelopes;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Enums\SigningOrder;
use App\Envelopes\Exceptions\FlowException;
use App\Evidence\EvidenceLogger;
use App\Evidence\RequestContext;
use App\Models\Signer;
use Illuminate\Support\Facades\Crypt;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Tag;
use App\Webhooks\WebhookDispatcher;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class EnvelopeService
{
    public function __construct(
        private readonly EvidenceLogger $evidence,
        private readonly WebhookDispatcher $webhooks,
    ) {
    }

    /**
     * @param  list<array{name:string,email:string,phone?:string,cpf?:string,role?:string,position?:int}>  $signers
     */
    public function create(
        Tenant $tenant,
        ?User $creator,
        string $pdfContents,
        string $filename,
        string $title,
        array $signers,
        SigningOrder $order = SigningOrder::Parallel,
        string $requiredLevel = 'simple',
        ?string $message = null,
        /** @var array{otp?:bool,selfie?:bool,document?:bool,geolocation?:bool} $verifications */
        array $verifications = [],
        /** @var array{validity_days?:int,reminder_every_days?:int|null,channels?:list<string>,folder_id?:string|null,tags?:list<string>,signature_fields?:list<array>} $options */
        array $options = [],
    ): Envelope {
        // required_level "advanced" (legado/API) equivale a exigir o código por e-mail.
        $otp = $verifications['otp'] ?? ($requiredLevel === 'advanced');
        $selfie = (bool) ($verifications['selfie'] ?? false);
        $docPhoto = (bool) ($verifications['document'] ?? false);
        $geolocation = (bool) ($verifications['geolocation'] ?? false);
        $requiredLevel = ($otp || $selfie || $docPhoto) ? 'advanced' : 'simple';

        $this->assertPdf($tenant, $pdfContents);
        $this->assertParticipants($signers, ! empty($options['sender']));

        $validity = max(1, min(365, (int) ($options['validity_days'] ?? config('signing.default_validity_days'))));
        $reminder = array_key_exists('reminder_every_days', $options)
            ? ($options['reminder_every_days'] === null ? null : max(1, min(30, (int) $options['reminder_every_days'])))
            : (int) config('signing.default_reminder_days');
        $channels = array_values(array_intersect($options['channels'] ?? ['email'], ['email', 'whatsapp', 'sms'])) ?: ['email'];
        $externalId = isset($options['external_id']) && $options['external_id'] !== '' ? (string) $options['external_id'] : null;
        if ($externalId !== null && Envelope::query()->where('tenant_id', $tenant->getKey())->where('external_id', $externalId)->exists()) {
            throw FlowException::because('duplicate_external_id', 'Já existe um documento com este external_id.');
        }
        $folderId = $options['folder_id'] ?? null;
        if ($folderId !== null && ! Folder::query()->where('tenant_id', $tenant->getKey())->whereKey($folderId)->exists()) {
            throw FlowException::because('invalid_folder', 'Pasta inválida.');
        }

        return DB::transaction(function () use ($tenant, $creator, $pdfContents, $filename, $title, $signers, $order, $requiredLevel, $message, $otp, $selfie, $docPhoto, $geolocation, $validity, $reminder, $channels, $folderId, $options, $externalId) {
            $document = $this->storeDocument($tenant, $pdfContents, $filename);

            $envelope = Envelope::query()->create([
                'tenant_id' => $tenant->getKey(),
                'created_by' => $creator?->getKey(),
                'document_id' => $document->getKey(),
                'title' => $title,
                'message' => $message,
                'status' => EnvelopeStatus::Draft,
                'signing_order' => $order,
                'required_level' => $requiredLevel,
                'require_otp' => $otp,
                'require_selfie' => $selfie,
                'require_document' => $docPhoto,
                'require_geolocation' => $geolocation,
                'verification_code' => strtoupper(Str::random(12)),
                'validity_days' => $validity,
                'reminder_every_days' => $reminder,
                'notify_channels' => $channels,
                'folder_id' => $folderId,
                'external_id' => $externalId,
                'metadata' => $options['metadata'] ?? null,
                'template_id' => $options['template_id'] ?? null,
            ]);

            if (! empty($options['tags'])) {
                $envelope->tags()->sync(
                    Tag::query()->where('tenant_id', $tenant->getKey())->whereIn('id', $options['tags'])->pluck('id')->all()
                );
            }

            // Remetente (a empresa): signatário marcado, sempre o primeiro (posição 0), sem link público.
            $sender = null;
            if (! empty($options['sender'])) {
                $sender = $envelope->signers()->create([
                    'name' => $options['sender']['name'],
                    'email' => mb_strtolower($options['sender']['email']),
                    'cpf' => $options['sender']['cpf'] ?? null,
                    'role' => 'signer',
                    'position' => 0,
                    'status' => SignerStatus::Pending,
                    'is_sender' => true,
                ]);
            }

            $created = [];
            $lastSignerPosition = collect(array_values($signers))
                ->map(fn ($s, $i) => ($s['role'] ?? 'signer') === 'signer' ? (int) ($s['position'] ?? $i + 1) : 0)
                ->max() ?: 1;

            foreach (array_values($signers) as $i => $signer) {
                $role = $signer['role'] ?? 'signer';
                $created[$i] = $envelope->signers()->create([
                    'name' => $signer['name'],
                    'email' => mb_strtolower($signer['email']),
                    'phone' => $signer['phone'] ?? null,
                    'cpf' => $signer['cpf'] ?? null,
                    'role' => $role,
                    // testemunhas formam o último grupo: depois de todos os signatários
                    'position' => $role === 'witness'
                        ? max((int) ($signer['position'] ?? 0), $lastSignerPosition + 1)
                        : ($signer['position'] ?? $i + 1),
                    'status' => SignerStatus::Pending,
                ]);
            }

            // Posição da assinatura no PDF: signer = índice na lista de signatários.
            foreach ($options['signature_fields'] ?? [] as $field) {
                $target = $field['signer'] === 'sender' ? $sender : ($created[$field['signer']] ?? null);
                if ($target === null) {
                    continue;
                }
                $envelope->signatureFields()->create([
                    'signer_id' => $target->getKey(),
                    'page' => max(1, (int) $field['page']),
                    'x' => min(max((float) $field['x'], 0), 1),
                    'y' => min(max((float) $field['y'], 0), 1),
                ]);
            }

            // Signatários e testemunhas viram contatos da empresa (o remetente não).
            app(\App\Contacts\ContactService::class)->rememberFromSigners($tenant, array_values($signers));

            $this->evidence->record($envelope, 'document_created', [
                'document_sha256' => $document->sha256,
                'signers' => count($signers) + ($sender ? 1 : 0),
                'required_level' => $requiredLevel,
                'verifications' => array_keys(array_filter([
                    'otp' => $otp, 'selfie' => $selfie, 'document' => $docPhoto, 'geolocation' => $geolocation,
                ])),
            ]);

            return $envelope;
        });
    }

    /** PDF de verdade, dentro do limite e cabendo no plano. */
    private function assertPdf(Tenant $tenant, string $pdfContents): void
    {
        if (! str_starts_with($pdfContents, '%PDF-')) {
            throw FlowException::because('invalid_document', 'O arquivo enviado não é um PDF válido.');
        }
        if (strlen($pdfContents) > (int) config('signing.max_pdf_mb') * 1024 * 1024) {
            throw FlowException::because('document_too_large', 'O PDF excede o limite de '.config('signing.max_pdf_mb').' MB.');
        }
        \App\Plans\Entitlements::for($tenant)->canUpload(strlen($pdfContents))->orFail();
    }

    /** Grava o PDF no disco e a linha do documento, somando ao armazenamento da empresa. */
    private function storeDocument(Tenant $tenant, string $pdfContents, string $filename): Document
    {
        $path = "tenants/{$tenant->getKey()}/documents/".Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $pdfContents);

        $document = Document::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => $filename,
            'storage_path' => $path,
            'sha256' => hash('sha256', $pdfContents),
            'size' => strlen($pdfContents),
        ]);
        app(\App\Plans\StorageUsage::class)->add($tenant, strlen($pdfContents));

        return $document;
    }

    private function assertDraft(Envelope $envelope): void
    {
        if ($envelope->status !== EnvelopeStatus::Draft) {
            throw FlowException::because('not_draft', 'Somente rascunhos podem ser alterados.');
        }
    }

    /** Avança o progresso do assistente sem nunca voltar atrás. */
    private function reachStep(Envelope $envelope, int $step): void
    {
        $envelope->update(['wizard_step' => max((int) $envelope->wizard_step, $step)]);
    }

    /** Passo 1: cria o rascunho só com o PDF (sem signatários). A recusa pelo signatário começa desligada no assistente. */
    public function createDraft(Tenant $tenant, User $creator, string $pdfContents, string $filename, string $title, ?string $folderId = null): Envelope
    {
        $this->assertPdf($tenant, $pdfContents);
        if ($folderId !== null && ! Folder::query()->where('tenant_id', $tenant->getKey())->whereKey($folderId)->exists()) {
            throw FlowException::because('invalid_folder', 'Pasta inválida.');
        }

        return DB::transaction(function () use ($tenant, $creator, $pdfContents, $filename, $title, $folderId) {
            $document = $this->storeDocument($tenant, $pdfContents, $filename);

            $envelope = Envelope::query()->create([
                'tenant_id' => $tenant->getKey(),
                'created_by' => $creator->getKey(),
                'document_id' => $document->getKey(),
                'title' => $title,
                'status' => EnvelopeStatus::Draft,
                'wizard_step' => 1,
                'allow_refuse' => false,
                'signing_order' => SigningOrder::Parallel,
                'required_level' => 'simple',
                'verification_code' => strtoupper(Str::random(12)),
                'validity_days' => (int) config('signing.default_validity_days'),
                'reminder_every_days' => (int) config('signing.default_reminder_days'),
                'notify_channels' => ['email'],
                'folder_id' => $folderId,
            ]);

            $this->evidence->record($envelope, 'document_created', [
                'document_sha256' => $document->sha256, 'signers' => 0, 'required_level' => 'simple', 'verifications' => [],
            ]);

            return $envelope;
        });
    }

    /** Passo 1 de novo: troca o PDF. Os campos posicionados são apagados (o número de páginas pode mudar). */
    public function replaceDocument(Envelope $envelope, string $pdfContents, string $filename): void
    {
        $this->assertDraft($envelope);
        $tenant = $envelope->tenant;
        $this->assertPdf($tenant, $pdfContents);

        $newPath = null;
        $oldPath = null;
        try {
            DB::transaction(function () use ($envelope, $tenant, $pdfContents, $filename, &$newPath, &$oldPath) {
                // Trava a linha e confere de novo: outra aba pode ter enviado o documento nesse meio tempo.
                $locked = Envelope::query()->whereKey($envelope->getKey())->lockForUpdate()->firstOrFail();
                $this->assertDraft($locked);

                $old = $locked->document;
                $new = $this->storeDocument($tenant, $pdfContents, $filename);
                $newPath = $new->storage_path;

                $locked->signatureFields()->delete();
                $locked->update(['document_id' => $new->getKey(), 'wizard_step' => 1]);
                $locked->touch();

                app(\App\Plans\StorageUsage::class)->remove($tenant, (int) $old->size);
                $oldPath = $old->storage_path;
                $old->delete();

                $this->evidence->record($locked, 'document_replaced', ['document_sha256' => $new->sha256]);
            });
        } catch (\Throwable $e) {
            if ($newPath !== null) {
                Storage::disk('local')->delete($newPath); // o banco desfez tudo: o arquivo novo ficaria órfão
            }

            throw $e;
        }

        // Só depois do commit o arquivo antigo pode sumir.
        if ($oldPath !== null) {
            Storage::disk('local')->delete($oldPath);
        }
        $envelope->unsetRelation('document');
        $envelope->refresh();
    }

    /**
     * Passo 2: signatários, testemunhas e remetente. Quem vem com `id` do próprio documento é mantido (e seus campos
     * também); quem some da lista é removido junto com os campos dele.
     *
     * @param  list<array{id?:string,name:string,email?:?string,phone?:?string,cpf?:?string,role?:string}>  $people
     * @param  array{otp?:bool,selfie?:bool,document?:bool,geolocation?:bool}  $verifications
     */
    public function syncPeople(Envelope $envelope, User $by, array $people, bool $withSender, SigningOrder $order, array $verifications = []): void
    {
        $this->assertDraft($envelope);
        $people = array_values($people);
        $this->assertParticipants($people, $withSender);
        if (count($people) > 20) {
            throw FlowException::because('too_many_signers', 'No máximo 20 signatários por documento.');
        }

        DB::transaction(function () use ($envelope, $by, $people, $withSender, $order, $verifications) {
            $existing = $envelope->signers()->get()->keyBy(fn (Signer $s) => (string) $s->getKey());
            $keep = [];
            foreach ($people as $p) {
                $id = (string) ($p['id'] ?? '');
                if ($id !== '' && $existing->has($id) && ! $existing[$id]->isSender()) {
                    $keep[] = $id;
                }
            }
            $envelope->signers()->where('is_sender', false)->whereNotIn('id', $keep)->delete();

            $sender = $existing->first(fn (Signer $s) => $s->isSender());
            if (! $withSender && $sender !== null) {
                $sender->delete();
            }
            if ($withSender && $sender === null) {
                $envelope->signers()->create([
                    'name' => $by->name, 'email' => mb_strtolower($by->email), 'role' => 'signer', 'position' => 0,
                    'status' => SignerStatus::Pending, 'is_sender' => true,
                ]);
            }

            $last = collect($people)->map(fn ($p, $i) => ($p['role'] ?? 'signer') === 'signer' ? $i + 1 : 0)->max() ?: 1;
            foreach ($people as $i => $p) {
                $role = $p['role'] ?? 'signer';
                $email = mb_strtolower(trim((string) ($p['email'] ?? '')));
                $attrs = [
                    'name' => trim((string) $p['name']),
                    'email' => $email === '' ? null : $email,
                    'phone' => ($p['phone'] ?? '') === '' ? null : ($p['phone'] ?? null),
                    'cpf' => ($p['cpf'] ?? '') === '' ? null : ($p['cpf'] ?? null),
                    'role' => $role,
                    'position' => $role === 'witness' ? $last + 1 : $i + 1,
                ];
                $id = (string) ($p['id'] ?? '');
                if (in_array($id, $keep, true)) {
                    $existing[$id]->update($attrs);
                } else {
                    $envelope->signers()->create($attrs + ['status' => SignerStatus::Pending]);
                }
            }

            $otp = (bool) ($verifications['otp'] ?? false);
            $selfie = (bool) ($verifications['selfie'] ?? false);
            $docPhoto = (bool) ($verifications['document'] ?? false);
            $envelope->update([
                'signing_order' => $order,
                'require_otp' => $otp,
                'require_selfie' => $selfie,
                'require_document' => $docPhoto,
                'require_geolocation' => (bool) ($verifications['geolocation'] ?? false),
                'required_level' => ($otp || $selfie || $docPhoto) ? 'advanced' : 'simple',
            ]);
            $this->reachStep($envelope, 2);
            $envelope->touch();

            app(\App\Contacts\ContactService::class)->rememberFromSigners($envelope->tenant, $people);
        });
    }

    /**
     * Passo 3: substitui o conjunto de campos posicionados no PDF.
     *
     * @param  list<array{signer_id:string,type:string,page:int,x:float,y:float,width:float,height:float}>  $fields
     */
    public function syncFields(Envelope $envelope, array $fields): void
    {
        $this->assertDraft($envelope);
        $fields = array_values($fields);
        if (count($fields) > 60) {
            throw FlowException::because('too_many_fields', 'No máximo 60 campos por documento.');
        }

        $mine = $envelope->signers()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $senderId = (string) ($envelope->signers()->where('is_sender', true)->value('id') ?? '');
        $rows = [];
        foreach ($fields as $f) {
            if (! in_array((string) ($f['signer_id'] ?? ''), $mine, true)) {
                throw FlowException::because('invalid_field_signer', 'Há um campo de um signatário que não está neste documento.');
            }
            if (! in_array($f['type'] ?? '', \App\Models\SignatureField::TYPES, true)) {
                throw FlowException::because('invalid_field_type', 'O tipo de campo não é válido.');
            }
            if ((string) $f['signer_id'] === $senderId && $f['type'] !== 'signature') {
                throw FlowException::because('invalid_field_type', 'O remetente só pode ter campo de assinatura (visto e texto são preenchidos por quem recebe o link).');
            }
            if ((int) ($f['page'] ?? 0) < 1 || (int) $f['page'] > 2000) {
                throw FlowException::because('invalid_field_page', 'A página do campo é inválida.');
            }
            $x = (float) ($f['x'] ?? -1);
            $y = (float) ($f['y'] ?? -1);
            $w = (float) ($f['width'] ?? 0);
            $h = (float) ($f['height'] ?? 0);
            if ($x < 0 || $y < 0 || $w <= 0 || $h <= 0 || $x + $w > 1.0001 || $y + $h > 1.0001) {
                throw FlowException::because('invalid_field_position', 'A posição do campo está fora da página.');
            }
            $rows[] = ['signer_id' => $f['signer_id'], 'type' => $f['type'], 'page' => (int) $f['page'], 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
        }

        DB::transaction(function () use ($envelope, $rows) {
            $envelope->signatureFields()->delete();
            foreach ($rows as $row) {
                $envelope->signatureFields()->create($row);
            }
            $this->reachStep($envelope, 3);
            $envelope->touch();
        });
    }

    /**
     * Passo 4: mensagem, lembretes, prazo, canais, recusa e etiquetas. Só grava as chaves que vierem.
     *
     * @param  array{message?:?string,reminder_every_days?:?int,validity_days?:?int,channels?:list<string>,allow_refuse?:bool,tags?:list<string>}  $options
     */
    public function applyDelivery(Envelope $envelope, array $options): void
    {
        $this->assertDraft($envelope);
        $update = [];

        if (array_key_exists('message', $options)) {
            $message = trim((string) $options['message']);
            if (mb_strlen($message) > 500) {
                throw FlowException::because('message_too_long', 'A mensagem pode ter no máximo 500 caracteres.');
            }
            $update['message'] = $message === '' ? null : $message;
        }
        if (array_key_exists('reminder_every_days', $options)) {
            $r = $options['reminder_every_days'];
            $update['reminder_every_days'] = $r === null ? null : max(1, min(30, (int) $r));
        }
        if (array_key_exists('validity_days', $options)) {
            $v = (int) $options['validity_days'];
            if ($v < 1 || $v > 365) {
                throw FlowException::because('invalid_validity', 'O prazo deve ficar entre 1 e 365 dias.');
            }
            $update['validity_days'] = $v;
        }
        if (array_key_exists('channels', $options)) {
            $channels = array_values(array_intersect((array) $options['channels'], ['email', 'whatsapp', 'sms', 'manual']));
            $update['notify_channels'] = in_array('manual', $channels, true) ? ['manual'] : ($channels ?: ['email']);
        }
        if (array_key_exists('allow_refuse', $options)) {
            $update['allow_refuse'] = (bool) $options['allow_refuse'];
        }
        if (array_key_exists('deadline_at', $options)) {
            $update['deadline_at'] = $options['deadline_at']; // fim do dia escolhido, ou null (usa validity_days)
        }

        DB::transaction(function () use ($envelope, $update, $options) {
            $envelope->forceFill($update)->save();
            $envelope->touch();
            if (array_key_exists('tags', $options)) {
                $envelope->tags()->sync(
                    Tag::query()->where('tenant_id', $envelope->tenant_id)->whereIn('id', (array) $options['tags'])->pluck('id')->all()
                );
            }
        });
    }

    /** O que precisa existir antes de liberar o rascunho. */
    public function assertSendable(Envelope $envelope, bool $autoSend): void
    {
        $this->assertDraft($envelope);
        $signers = $envelope->signers()->get();

        if (! $signers->contains(fn (Signer $s) => $s->isSender() || $s->role === 'signer')) {
            throw FlowException::because('no_signers', 'Informe ao menos um signatário.');
        }
        $missingEmail = fn () => $signers->contains(fn (Signer $s) => ! $s->isSender() && ! filter_var((string) $s->email, FILTER_VALIDATE_EMAIL));
        if ($envelope->require_otp && $missingEmail()) {
            throw FlowException::because('otp_email_required', 'O código por e-mail exige o e-mail de todos os signatários.');
        }
        if ($autoSend && $missingEmail()) {
            throw FlowException::because('email_required', 'Informe o e-mail de todos os signatários para enviar os links automaticamente.');
        }
    }

    /** Papéis aceitos, limite de testemunhas e ao menos um signatário. @param list<array<string,mixed>> $signers */
    private function assertParticipants(array $signers, bool $hasSender = false): void
    {
        $roles = array_map(fn ($s) => $s['role'] ?? 'signer', $signers);

        foreach ($roles as $role) {
            if (! in_array($role, Envelope::PARTICIPANT_ROLES, true)) {
                throw FlowException::because('invalid_role', 'Papel inválido: use signer ou witness.');
            }
        }

        if (count(array_filter($roles, fn ($r) => $r === 'witness')) > (int) config('signing.max_witnesses')) {
            throw FlowException::because('too_many_witnesses', 'Limite de '.config('signing.max_witnesses').' testemunhas por documento.');
        }

        if (! in_array('signer', $roles, true) && ! $hasSender) {
            throw FlowException::because('no_signers', 'Informe ao menos um signatário.');
        }
    }

    /**
     * Envia o documento. Retorna o token em texto puro de cada signatário (id => token);
     * só o hash é guardado. O token deve ser entregue por e-mail/WhatsApp.
     *
     * @return array<string,string>
     */
    public function send(Envelope $envelope, ?RequestContext $context = null, ?int $validDays = null): array
    {
        $validDays ??= (int) ($envelope->validity_days ?: config('signing.default_validity_days'));

        if ($envelope->isTrashed()) {
            throw FlowException::because('trashed', 'Este documento está na lixeira. Restaure-o antes de enviar.');
        }

        if ($envelope->status !== EnvelopeStatus::Draft) {
            throw FlowException::because('not_draft', 'Somente rascunhos podem ser enviados.');
        }

        $tenant = $envelope->tenant;
        if ($tenant->isSuspended()) {
            throw FlowException::because('tenant_suspended', 'Esta conta está suspensa. Entre em contato com o suporte.');
        }

        // Rascunho do assistente pode estar incompleto: sem signatário ou sem e-mail necessário não há como assinar.
        $this->assertSendable($envelope, ($envelope->notify_channels ?? ['email']) !== ['manual']);
        if ($envelope->deadline_at !== null && $envelope->deadline_at->isPast()) {
            throw FlowException::because('deadline_passed', 'A data limite escolhida já passou. Escolha outra no último passo.');
        }

        // Limites do plano (documentos, teste vencido, assinatura bloqueada). A cobrança com trava vem logo abaixo.
        \App\Plans\Entitlements::for($tenant)->canSendDocument()->orFail();

        if ($tenant->monthly_envelope_limit !== null) {
            $used = Envelope::query()->where('tenant_id', $tenant->getKey())->where('sent_at', '>=', now()->startOfMonth())->count();
            if ($used >= $tenant->monthly_envelope_limit) {
                throw FlowException::because('quota_exceeded', 'Limite mensal de documentos do plano atingido.');
            }
        }

        $tokens = DB::transaction(function () use ($envelope, $context, $validDays) {
            $tokens = [];

            foreach ($envelope->signers as $signer) {
                if ($signer->isSender()) {
                    $signer->update(['invited_at' => now()]); // o remetente não recebe link nem convite
                    continue;
                }

                $plain = Str::random(48);
                $signer->update([
                    'token_hash' => hash('sha256', $plain),
                    'token_encrypted' => Crypt::encryptString($plain),
                ]);
                $tokens[$signer->getKey()] = $plain;
            }

            $envelope->update([
                'status' => EnvelopeStatus::Sent,
                'sent_at' => now(),
                'expires_at' => $envelope->deadline_at ?? now()->addDays($validDays),
            ]);

            $this->evidence->record($envelope, 'document_sent', ['expires_at' => $envelope->expires_at?->toIso8601String()], null, $context);
            app(\App\Plans\UsageLedger::class)->charge($envelope, 'send'); // sem saldo: lança e desfaz o envio

            return $tokens;
        });

        app(\App\Plans\PlanUsageMonitor::class)->check($tenant);
        $this->webhooks->dispatch($envelope, 'document.sent');

        return $tokens;
    }

    /**
     * Envia o documento e já assina como remetente (sem verificações): a assinatura vem de uma sessão logada ou de uma
     * chave de API. Devolve os tokens dos demais signatários (o remetente não tem link).
     *
     * @return array<string,string>
     */
    public function sendAndSignAsSender(Envelope $envelope, SenderSignature $signature, ?RequestContext $context = null, ?int $validDays = null, ?array $senderIdentity = null): array
    {
        // Tudo ou nada: se a assinatura do remetente falhar, o documento volta a rascunho (nada de documento preso
        // "enviado" com remetente pendente e sem link). A identidade só muda depois que send() validou o rascunho.
        return DB::transaction(function () use ($envelope, $signature, $context, $validDays, $senderIdentity) {
            $tokens = $this->send($envelope, $context, $validDays);

            if ($senderIdentity !== null) {
                // O remetente é quem de fato assina (quem liberou no painel, ou o representante na API).
                $envelope->signers()->where('is_sender', true)->update([
                    'name' => $senderIdentity['name'],
                    'email' => mb_strtolower($senderIdentity['email']),
                    'cpf' => $senderIdentity['cpf'] ?? null,
                ]);
            }

            app(SenderSigner::class)->apply($envelope->fresh(), $signature, $context);

            return $tokens;
        });
    }

    /**
     * Gera um link novo para o signatário (o anterior deixa de funcionar). Retorna o token em texto puro.
     */
    public function regenerateLink(Signer $signer, ?RequestContext $context = null): string
    {
        if ($signer->isSender()) {
            throw FlowException::because('sender_no_link', 'O remetente assina pelo painel ou pela API e não tem link de assinatura.');
        }

        $envelope = $signer->envelope;

        if (! $envelope->status->isOpenForSigning()) {
            throw FlowException::because('document_closed', 'Só é possível gerar link de documentos em andamento.');
        }
        if (in_array($signer->status, [SignerStatus::Signed, SignerStatus::Refused], true)) {
            throw FlowException::because('signer_done', 'Este signatário já respondeu.');
        }

        $plain = Str::random(48);
        $signer->update(['token_hash' => hash('sha256', $plain), 'token_encrypted' => Crypt::encryptString($plain)]);
        $this->evidence->record($envelope, 'link_regenerated', [], $signer, $context);

        return $plain;
    }

    public function cancel(Envelope $envelope, ?RequestContext $context = null): void
    {
        if (! $envelope->status->isOpenForSigning() && $envelope->status !== EnvelopeStatus::Draft) {
            throw FlowException::because('not_cancellable', 'Este documento não pode ser cancelado.');
        }

        $envelope->update(['status' => EnvelopeStatus::Cancelled]);
        $this->evidence->record($envelope, 'document_cancelled', [], null, $context);
        app(\App\Plans\UsageLedger::class)->refund($envelope);
        $this->webhooks->dispatch($envelope, 'document.cancelled');
    }
}
