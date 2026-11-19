<?php

declare(strict_types=1);

namespace App\Assistant\Flow;

use App\Assistant\AssistantReply;
use App\Assistant\Channel\InboundMessage;
use App\Assistant\Channel\MetaException;
use App\Assistant\Channel\MetaWhatsAppClient;
use App\Assistant\ToolGroups;
use App\Contacts\ContactService;
use App\Documents\DocumentIntake;
use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SenderPolicy;
use App\Envelopes\TrashService;
use App\Models\Envelope;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Notifications\SignerNotifier;
use App\Plans\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * Conversa guiada, sem IA, para enviar um documento para assinatura pelo WhatsApp:
 * arquivo → título → signatários (nome e e-mail) → revisão → envio. Usa o mesmo rascunho, os mesmos limites do plano
 * e o mesmo envio do site. Devolve null quando a mensagem não é do fluxo (segue para o assistente normal).
 */
final class SendDocumentFlow
{
    private const MAX_SIGNERS = 20;
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(
        private readonly FlowStore $store,
        private readonly MetaWhatsAppClient $client,
        private readonly DocumentIntake $intake,
        private readonly EnvelopeService $envelopes,
        private readonly SignerNotifier $notifier,
        private readonly ContactService $contacts,
    ) {
    }

    public function handle(User $user, InboundMessage $m): ?AssistantReply
    {
        $isDoc = $m->type === 'document';
        $isFlowButton = $m->payloadId !== null && str_starts_with($m->payloadId, 'flow_');
        $text = trim((string) $m->text);
        $wantsStart = $m->type === 'text' && preg_match('/\b(enviar|mandar|criar|novo|nova)\b.{0,25}\bdocumento\b|\bpara assinatura\b|\bmandar (um )?contrato\b/iu', $text) === 1;

        if (! ToolGroups::allows($user->tenant, 'send_documents')) {
            return ($isDoc || $isFlowButton || $wantsStart) ? new AssistantReply('Enviar documentos pelo WhatsApp não está liberado para a sua empresa. Fale com o administrador.') : null;
        }

        $flow = $this->store->current($user);

        if ($isDoc) {
            return $this->receiveFile($user, $m, $flow);
        }
        if ($isFlowButton) {
            return $flow === null
                ? new AssistantReply('Não há um envio em andamento (ele já foi concluído ou expirou). Mande o arquivo em PDF ou Word para começar.')
                : $this->button($user, (string) $m->payloadId, $flow);
        }
        if (! in_array($m->type, ['text'], true)) {
            return null;
        }
        if ($flow !== null) {
            return preg_match('/^(cancelar|cancela|sair)$/iu', $text) === 1 ? $this->cancel($user, $flow) : $this->typed($user, $flow, $text);
        }

        return $wantsStart ? new AssistantReply('Para enviar um documento, mande aqui o arquivo em PDF ou Word (DOCX). Eu pergunto o título e quem vai assinar.') : null;
    }

    // ---------------------------------------------------------------- arquivo

    private function receiveFile(User $user, InboundMessage $m, ?WhatsAppFlow $flow): AssistantReply
    {
        if (($why = $this->blockReason($user)) !== null) {
            return new AssistantReply($why);
        }

        $name = trim((string) $m->filename) !== '' ? (string) $m->filename : ($m->mime === 'application/pdf' ? 'documento.pdf' : 'documento');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, ['pdf', 'docx'], true) && ! in_array($m->mime, ['application/pdf', self::DOCX_MIME], true)) {
            return new AssistantReply('Só consigo receber arquivos em PDF ou Word (DOCX). Salve o documento num desses formatos e mande de novo.');
        }
        if ($ext === '' || ! in_array($ext, ['pdf', 'docx'], true)) {
            $name .= $m->mime === self::DOCX_MIME ? '.docx' : '.pdf';
        }
        if ($m->mediaId === null || $m->mediaId === '') {
            return new AssistantReply('Não consegui baixar o arquivo. Mande de novo.');
        }

        if ($flow !== null && ! empty($flow->data['envelope_id'])) {
            $this->store->advance($flow, (string) $flow->step, ['pending_media' => ['id' => $m->mediaId, 'name' => $name, 'mime' => $m->mime]]);

            return new AssistantReply('Você já está enviando *'.($flow->data['title'] ?? $flow->data['filename'] ?? 'um documento').'*. Quer descartar e começar com *'.$name.'*?', [['id' => 'flow_discard', 'title' => 'Descartar e trocar'], ['id' => 'flow_keep', 'title' => 'Manter o atual']]);
        }

        return $this->startWith($user, $m->mediaId, $name);
    }

    private function startWith(User $user, string $mediaId, string $name): AssistantReply
    {
        try {
            $file = $this->client->downloadMedia($mediaId);
        } catch (MetaException) {
            return new AssistantReply('Não consegui baixar o arquivo. Mande de novo.');
        }
        $maxMb = (int) config('signing.max_pdf_mb');
        if (strlen($file['bytes']) > $maxMb * 1024 * 1024) {
            return new AssistantReply("O arquivo é grande demais: o limite é de {$maxMb} MB por documento.");
        }

        try {
            $doc = $this->intake->fromBytes($file['bytes'], $name);
            $title = $this->defaultTitle($doc['name']);
            $envelope = $this->envelopes->createDraft($user->tenant, $user, $doc['pdf'], $doc['name'], $title);
        } catch (FlowException $e) {
            return new AssistantReply($e->getMessage());
        }

        $this->store->open($user, 'title', ['envelope_id' => $envelope->getKey(), 'filename' => $doc['name'], 'title' => $title, 'signers' => [], 'order' => 'parallel']);

        return $this->prompt($this->store->current($user));
    }

    // ---------------------------------------------------------------- botões

    private function button(User $user, string $id, WhatsAppFlow $flow): AssistantReply
    {
        $step = (string) $flow->step;

        switch ($id) {
            case 'flow_cancel':
                return $this->cancel($user, $flow);

            case 'flow_discard':
                $pending = $flow->data['pending_media'] ?? null;
                if (! is_array($pending)) {
                    return $this->prompt($flow);
                }
                $this->discardDraft($user, $flow);
                $this->store->clear($user);

                return $this->startWith($user, (string) $pending['id'], (string) $pending['name']);

            case 'flow_keep':
                $data = (array) $flow->data;
                unset($data['pending_media']);
                $flow->forceFill(['data' => $data])->save();

                return new AssistantReply('Ok, continuamos com *'.($data['title'] ?? 'o documento atual').'*.', $this->prompt($flow)->buttons);

            case 'flow_title_keep':
                return $step === 'title' ? $this->setTitle($flow, (string) ($flow->data['title'] ?? 'Documento')) : $this->prompt($flow);

            case 'flow_done':
                if ($step !== 'signers' && $step !== 'review') {
                    return $this->prompt($flow);
                }
                if (empty($flow->data['signers'])) {
                    return new AssistantReply('Adicione ao menos uma pessoa antes de continuar. Mande o nome e o e-mail.', [['id' => 'flow_cancel', 'title' => 'Cancelar']]);
                }
                $this->store->advance($flow, 'review');

                return $this->review($flow->refresh());

            case 'flow_order':
                if ($step !== 'review') {
                    return $this->prompt($flow);
                }
                $order = ($flow->data['order'] ?? 'parallel') === 'sequential' ? 'parallel' : 'sequential';
                $this->store->advance($flow, 'review', ['order' => $order]);

                return $this->review($flow->refresh());

            case 'flow_send':
                return $this->send($user, $flow);
        }

        return $this->prompt($flow);
    }

    // ---------------------------------------------------------------- texto digitado

    private function typed(User $user, WhatsAppFlow $flow, string $text): AssistantReply
    {
        return match ((string) $flow->step) {
            'title' => $this->setTitle($flow, $this->cleanTitle($text) ?: (string) ($flow->data['title'] ?? 'Documento')),
            'signers', 'review' => $this->addSigner($user, $flow, $text),
            default => new AssistantReply('Estou terminando o envio. Aguarde um instante.'),
        };
    }

    private function setTitle(WhatsAppFlow $flow, string $title): AssistantReply
    {
        $envelope = Envelope::query()->where('tenant_id', $flow->tenant_id)->whereKey($flow->data['envelope_id'] ?? '')->first();
        if ($envelope === null || $envelope->status !== EnvelopeStatus::Draft) {
            $this->store->clear(User::query()->findOrFail($flow->user_id));

            return new AssistantReply('Não encontrei o rascunho deste envio. Mande o arquivo de novo para recomeçar.');
        }
        $envelope->forceFill(['title' => $title])->save();
        $this->store->advance($flow, 'signers', ['title' => $title]);

        return $this->prompt($flow->refresh());
    }

    private function addSigner(User $user, WhatsAppFlow $flow, string $text): AssistantReply
    {
        $line = SignerLine::parse($text);
        if ($line === null) {
            return new AssistantReply('Não entendi. Mande um por mensagem, com nome e e-mail. Exemplo: Maria Souza maria@email.com', [['id' => 'flow_cancel', 'title' => 'Cancelar']]);
        }

        if ($line['email'] === null) {
            $found = $this->contacts->search($user->tenant, $line['name'], 3);
            $withEmail = $found->filter(fn ($c) => filter_var((string) $c->email, FILTER_VALIDATE_EMAIL))->values();
            if ($found->count() === 1 && $withEmail->count() === 1) {
                $line = ['name' => (string) $withEmail[0]->name, 'email' => mb_strtolower((string) $withEmail[0]->email)];
            } else {
                return new AssistantReply('Preciso do e-mail de '.$line['name'].'. Mande o nome e o e-mail juntos, por exemplo: '.$line['name'].' pessoa@email.com');
            }
        }

        $signers = (array) ($flow->data['signers'] ?? []);
        if (count($signers) >= self::MAX_SIGNERS) {
            return new AssistantReply('O limite é de '.self::MAX_SIGNERS.' pessoas por documento.');
        }
        foreach ($signers as $s) {
            if (mb_strtolower((string) $s['email']) === $line['email']) {
                return new AssistantReply($line['email'].' já está na lista.');
            }
        }

        $signers[] = ['name' => $line['name'], 'email' => $line['email']];
        $this->store->advance($flow, (string) $flow->step === 'review' ? 'review' : 'signers', ['signers' => $signers]);

        if ((string) $flow->step === 'review') {
            return $this->review($flow->refresh());
        }

        return new AssistantReply('Adicionei '.$line['name'].' ('.$line['email'].'): '.count($signers).' pessoa(s) na lista.'."\n".'Mande a próxima pessoa ou toque em Pronto.', [['id' => 'flow_done', 'title' => 'Pronto'], ['id' => 'flow_cancel', 'title' => 'Cancelar']]);
    }

    // ---------------------------------------------------------------- revisão e envio

    private function review(WhatsAppFlow $flow): AssistantReply
    {
        $d = (array) $flow->data;
        $lines = [];
        foreach ((array) ($d['signers'] ?? []) as $i => $s) {
            $lines[] = ($i + 1).'. '.$s['name'].' ('.$s['email'].')';
        }
        $sequential = ($d['order'] ?? 'parallel') === 'sequential';
        $days = (int) config('signing.default_validity_days');
        $remind = (int) config('signing.default_reminder_days');

        $text = "Revise antes de enviar:\n*".($d['title'] ?? 'Documento')."*\n".implode("\n", $lines)."\n\n"
            .'Ordem: '.($sequential ? 'um de cada vez, na ordem da lista' : 'todos assinam ao mesmo tempo')."\n"
            ."Prazo: {$days} dias".($remind > 0 ? ", lembrete a cada {$remind} dias" : '')."\n"
            .'Os links de assinatura vão por e-mail.'."\n".'Para incluir mais alguém, mande o nome e o e-mail.';

        return new AssistantReply($text, [['id' => 'flow_send', 'title' => 'Enviar agora'], ['id' => 'flow_order', 'title' => $sequential ? 'Todos juntos' : 'Um de cada vez'], ['id' => 'flow_cancel', 'title' => 'Cancelar']]);
    }

    private function send(User $user, WhatsAppFlow $flow): AssistantReply
    {
        if ((string) $flow->step === 'sending') {
            return new AssistantReply('Este envio já foi iniciado. Aguarde a confirmação.');
        }
        if ((string) $flow->step !== 'review') {
            return $this->prompt($flow);
        }
        // Reivindica o envio: um segundo toque no botão não envia de novo.
        if (WhatsAppFlow::query()->whereKey($flow->getKey())->where('step', 'review')->update(['step' => 'sending', 'updated_at' => now()]) === 0) {
            return new AssistantReply('Este envio já foi iniciado. Aguarde a confirmação.');
        }

        $d = (array) $flow->data;
        $envelope = Envelope::query()->where('tenant_id', $user->tenant_id)->where('created_by', $user->getKey())->whereKey($d['envelope_id'] ?? '')->first();
        if ($envelope === null || $envelope->isTrashed()) {
            $this->store->clear($user);

            return new AssistantReply('Não encontrei o rascunho deste envio. Mande o arquivo de novo para recomeçar.');
        }

        $people = array_map(fn ($s) => ['name' => (string) $s['name'], 'email' => (string) $s['email'], 'role' => 'signer'], (array) ($d['signers'] ?? []));
        $order = ($d['order'] ?? 'parallel') === 'sequential' ? SigningOrder::Sequential : SigningOrder::Parallel;

        try {
            if (($why = $this->blockReason($user)) !== null) {
                throw FlowException::because('sender_policy', $why);
            }
            DB::transaction(function () use ($envelope, $user, $people, $order): void {
                $locked = Envelope::query()->whereKey($envelope->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->status !== EnvelopeStatus::Draft) {
                    throw FlowException::because('not_draft', 'Este documento já foi enviado.');
                }
                $this->envelopes->syncPeople($locked, $user, $people, false, $order);
                $this->envelopes->assertSendable($locked->refresh(), true);
                $this->envelopes->send($locked);
            });
        } catch (FlowException $e) {
            if ($e->reason === 'not_draft') {
                $this->store->clear($user);

                return new AssistantReply('Este documento já foi enviado.');
            }
            $this->store->advance($flow->refresh(), 'review');

            return new AssistantReply('Não consegui enviar: '.$e->getMessage()."\nO rascunho continua salvo.", $this->review($flow->refresh())->buttons);
        }

        $envelope->refresh();
        $failed = $this->notifier->inviteActive($envelope);
        $this->store->clear($user);

        $n = count($people);
        $sequential = $order === SigningOrder::Sequential;
        $out = "Documento enviado para assinatura!\n*{$envelope->title}*\n"
            .($sequential ? 'O primeiro da lista já recebeu o link por e-mail; os próximos recebem na vez.' : "{$n} pessoa(s) receberam o link por e-mail.");
        if ($failed !== []) {
            $out .= "\nNão consegui enviar o e-mail para: ".implode(', ', $failed).'. Copie o link no site.';
        }
        $out .= "\nVocê recebe um aviso aqui a cada assinatura. Acompanhe também no site: ".route('envelopes.show', $envelope);

        return new AssistantReply($out);
    }

    // ---------------------------------------------------------------- cancelar e apoio

    private function cancel(User $user, WhatsAppFlow $flow): AssistantReply
    {
        $this->discardDraft($user, $flow);
        $this->store->clear($user);

        return new AssistantReply('Cancelado. O rascunho foi para a lixeira. Quando quiser, mande outro arquivo.');
    }

    private function discardDraft(User $user, WhatsAppFlow $flow): void
    {
        $envelope = Envelope::query()->where('tenant_id', $user->tenant_id)->where('created_by', $user->getKey())->whereKey($flow->data['envelope_id'] ?? '')->first();
        if ($envelope !== null && $envelope->status === EnvelopeStatus::Draft && ! $envelope->isTrashed()) {
            app(TrashService::class)->trash($envelope);
        }
    }

    private function prompt(WhatsAppFlow $flow): AssistantReply
    {
        $d = (array) $flow->data;

        return match ((string) $flow->step) {
            'title' => new AssistantReply('Recebi o arquivo *'.($d['filename'] ?? 'documento')."*.\nQual será o título do documento? Escreva o título ou toque em Manter o nome.\nTítulo atual: ".($d['title'] ?? ''), [['id' => 'flow_title_keep', 'title' => 'Manter o nome'], ['id' => 'flow_cancel', 'title' => 'Cancelar']]),
            'signers' => new AssistantReply('Título: *'.($d['title'] ?? '')."*\nQuem vai assinar? Mande o nome e o e-mail de uma pessoa por mensagem. Exemplo: Maria Souza maria@email.com\nSe a pessoa já está nos seus contatos, basta o nome.", ! empty($d['signers']) ? [['id' => 'flow_done', 'title' => 'Pronto'], ['id' => 'flow_cancel', 'title' => 'Cancelar']] : [['id' => 'flow_cancel', 'title' => 'Cancelar']]),
            'review' => $this->review($flow),
            default => new AssistantReply('Estou terminando o envio. Aguarde um instante.'),
        };
    }

    /** O motivo para NÃO aceitar um documento novo por aqui (política do remetente e limites do plano), ou null. */
    private function blockReason(User $user): ?string
    {
        $tenant = $user->tenant;
        try {
            $wants = app(SenderPolicy::class)->wantsSenderSignature($tenant, false);
        } catch (FlowException) {
            $wants = true;
        }
        if ($wants) {
            return 'Sua empresa exige que o remetente assine os documentos. Isso precisa ser feito no site; crie o documento por lá.';
        }

        $decision = Entitlements::for($tenant)->canSendDocument();

        return $decision->allowed ? null : $decision->message;
    }

    private function defaultTitle(string $filename): string
    {
        $base = (string) preg_replace('/\.(pdf|docx)$/i', '', $filename);
        $base = $this->cleanTitle(str_replace('_', ' ', $base));

        return $base !== '' ? $base : 'Documento';
    }

    private function cleanTitle(string $text): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text)));

        return mb_substr($t, 0, 190);
    }
}
