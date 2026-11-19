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
use App\Evidence\RequestContext;
use App\Models\Envelope;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Notifications\SignerNotifier;
use App\Plans\Entitlements;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Conversa guiada, sem IA, para enviar um documento para assinatura pelo WhatsApp:
 * arquivo → título → signatários (nome e e-mail) → revisão → envio. Usa o mesmo rascunho, os mesmos limites do plano
 * e o mesmo envio do site. Devolve null quando a mensagem não é do fluxo (segue para o assistente normal).
 */
final class SendDocumentFlow
{
    private const MAX_SIGNERS = 20;
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    /** O job da fila tem 80 s: baixar (até 45 s) + converter (30 s) cabe com folga. */
    private const CONVERT_SECONDS = 30;
    private const BUSY = 'Há um envio em andamento. Aguarde a confirmação antes de cancelar ou trocar o arquivo.';

    /** Carimbo (8 primeiros caracteres do id do rascunho) que vai em cada botão: botão de outro envio é reconhecido como antigo. */
    private ?string $stamp = null;

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
        $wantsStart = $m->type === 'text' && self::wantsStart($text);

        if (! ToolGroups::allows($user->tenant, 'send_documents')) {
            return ($isDoc || $isFlowButton || $wantsStart) ? new AssistantReply('Enviar documentos pelo WhatsApp não está liberado para a sua empresa. Fale com o administrador.') : null;
        }

        $flow = $this->store->current($user);
        $this->stamp = $flow !== null ? $this->stampOf($flow) : null;

        if ($isDoc) {
            return $this->receiveFile($user, $m, $flow);
        }
        if ($isFlowButton) {
            if ($flow === null) {
                return new AssistantReply('Não há um envio em andamento (ele já foi concluído ou expirou; se ficou um rascunho, ele continua no site). Mande o arquivo em PDF ou Word para começar.');
            }
            [$base, $given] = array_pad(explode(':', (string) $m->payloadId, 2), 2, null);
            if ($given !== null && $given !== $this->stamp) {
                return new AssistantReply('Esses botões são de um envio antigo. Use os botões da mensagem mais recente (o rascunho antigo continua no site).');
            }

            return $this->button($user, (string) $base, $flow);
        }
        if ($m->type !== 'text') {
            return null;
        }
        if ($flow !== null) {
            if (preg_match('/^(cancelar|cancela|sair)$/iu', $text) === 1) {
                return (string) $flow->step === 'sending' ? new AssistantReply(self::BUSY) : $this->cancel($user, $flow);
            }

            return $this->typed($user, $flow, $text);
        }

        return $wantsStart ? new AssistantReply('Para enviar um documento, mande aqui o arquivo em PDF ou Word (DOCX). Eu pergunto o título e quem vai assinar.') : null;
    }

    /** Só o pedido direto e curto ("enviar documento", "quero mandar um documento para assinar"): perguntas e frases soltas seguem para o assistente. */
    public static function wantsStart(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) > 60 || str_contains($t, '?')) {
            return false;
        }

        return preg_match('/^(?:(?:quero|preciso|vou|gostaria de|queria|posso)\s+)?(?:enviar|mandar|criar|fazer)\s+(?:um\s+|o\s+)?(?:novo\s+)?(?:documento|contrato)(?:\s+(?:para|pra)\s+(?:assinar|assinatura))?\s*[.!]?$/iu', $t) === 1
            || preg_match('/^(?:novo|nova)\s+documento\s*[.!]?$/iu', $t) === 1;
    }

    // ---------------------------------------------------------------- arquivo

    private function receiveFile(User $user, InboundMessage $m, ?WhatsAppFlow $flow): AssistantReply
    {
        if ($flow !== null && (string) $flow->step === 'sending') {
            return new AssistantReply(self::BUSY);
        }
        if (($why = $this->blockReason($user)) !== null) {
            return new AssistantReply($why);
        }

        $original = trim((string) $m->filename) !== '' ? (string) $m->filename : ($m->mime === 'application/pdf' ? 'documento.pdf' : 'documento');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (! in_array($ext, ['pdf', 'docx'], true)) {
            if ($m->mime === 'application/pdf') {
                $ext = 'pdf';
            } elseif ($m->mime === self::DOCX_MIME) {
                $ext = 'docx';
            } else {
                return new AssistantReply('Só consigo receber arquivos em PDF ou Word (DOCX). Salve o documento num desses formatos e mande de novo.');
            }
        }
        $name = $this->cleanFilename($original, $ext);
        if ($m->mediaId === null || $m->mediaId === '') {
            return new AssistantReply('Não consegui baixar o arquivo. Mande de novo.');
        }

        if ($flow !== null && ! empty($flow->data['envelope_id'])) {
            $this->store->advance($flow, (string) $flow->step, ['pending_media' => ['id' => $m->mediaId, 'name' => $name]]);

            return new AssistantReply('Você já está enviando *'.($flow->data['title'] ?? $flow->data['filename'] ?? 'um documento').'*. Quer descartar e começar com *'.$name.'*?', [$this->btn('flow_discard', 'Descartar e trocar'), $this->btn('flow_keep', 'Manter o atual')]);
        }

        $new = $this->prepare($user, $m->mediaId, $name);
        if ($new instanceof AssistantReply) {
            return $new;
        }

        return $this->openFlow($user, $new);
    }

    /**
     * Baixa o arquivo, converte o Word e cria o rascunho. Devolve os dados do novo envio ou a resposta de erro.
     *
     * @return array{envelope:Envelope,name:string,title:string}|AssistantReply
     */
    private function prepare(User $user, string $mediaId, string $name): array|AssistantReply
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
            $doc = $this->intake->fromBytes($file['bytes'], $name, self::CONVERT_SECONDS);
            $title = $this->defaultTitle($doc['name']);
            $envelope = $this->envelopes->createDraft($user->tenant, $user, $doc['pdf'], $doc['name'], $title);
        } catch (FlowException $e) {
            return new AssistantReply($e->getMessage());
        }

        return ['envelope' => $envelope, 'name' => $doc['name'], 'title' => $title];
    }

    /** @param array{envelope:Envelope,name:string,title:string} $new */
    private function openFlow(User $user, array $new): AssistantReply
    {
        $flow = $this->store->open($user, 'title', ['envelope_id' => $new['envelope']->getKey(), 'filename' => $new['name'], 'title' => $new['title'], 'signers' => [], 'order' => 'parallel']);
        $this->stamp = $this->stampOf($flow);

        return $this->prompt($flow);
    }

    // ---------------------------------------------------------------- botões

    private function button(User $user, string $id, WhatsAppFlow $flow): AssistantReply
    {
        $step = (string) $flow->step;

        switch ($id) {
            case 'flow_cancel':
                return $step === 'sending' ? new AssistantReply(self::BUSY) : $this->cancel($user, $flow);

            case 'flow_discard':
                if ($step === 'sending') {
                    return new AssistantReply(self::BUSY);
                }
                $pending = $flow->data['pending_media'] ?? null;
                if (! is_array($pending)) {
                    return $this->prompt($flow);
                }
                if (($why = $this->blockReason($user)) !== null) {
                    $this->dropPending($flow);

                    return new AssistantReply($why);
                }
                // O arquivo novo precisa ser aceito ANTES de descartar o atual: se falhar, o progresso não se perde.
                $new = $this->prepare($user, (string) $pending['id'], (string) $pending['name']);
                if ($new instanceof AssistantReply) {
                    $this->dropPending($flow);

                    return new AssistantReply($new->text."\nO envio atual continua: *".($flow->data['title'] ?? 'documento').'*.', $this->prompt($flow->refresh())->buttons);
                }
                $this->discardDraft($user, $flow);

                return $this->openFlow($user, $new);

            case 'flow_keep':
                $this->dropPending($flow);

                return new AssistantReply('Ok, continuamos com *'.($flow->data['title'] ?? 'o documento atual').'*.', $this->prompt($flow->refresh())->buttons);

            case 'flow_title_keep':
                return $step === 'title' ? $this->setTitle($flow, (string) ($flow->data['title'] ?? 'Documento')) : $this->prompt($flow);

            case 'flow_done':
                if ($step !== 'signers' && $step !== 'review') {
                    return $this->prompt($flow);
                }
                if (empty($flow->data['signers'])) {
                    return new AssistantReply('Adicione ao menos uma pessoa antes de continuar. Mande o nome e o e-mail.', [$this->btn('flow_cancel', 'Cancelar')]);
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

    private function dropPending(WhatsAppFlow $flow): void
    {
        $data = (array) $flow->data;
        unset($data['pending_media']);
        $flow->forceFill(['data' => $data])->save();
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
            return new AssistantReply('Não entendi. Mande um por mensagem, com nome e e-mail (só letras sem acento no e-mail). Exemplo: Maria Souza maria@email.com', [$this->btn('flow_cancel', 'Cancelar')]);
        }

        if ($line['email'] === null) {
            $found = $this->contacts->search($user->tenant, $line['name'], 3);
            $withEmail = $found->filter(fn ($c) => filter_var((string) $c->email, FILTER_VALIDATE_EMAIL))->values();
            if ($found->count() === 1 && $withEmail->count() === 1) {
                $line = ['name' => $this->cleanTitle((string) $withEmail[0]->name), 'email' => mb_strtolower((string) $withEmail[0]->email)];
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
        $inReview = (string) $flow->step === 'review';
        $this->store->advance($flow, $inReview ? 'review' : 'signers', ['signers' => $signers]);

        if ($inReview) {
            return $this->review($flow->refresh());
        }

        return new AssistantReply('Adicionei '.$line['name'].' ('.$line['email'].'): '.count($signers).' pessoa(s) na lista.'."\n".'Mande a próxima pessoa ou toque em Pronto.', [$this->btn('flow_done', 'Pronto'), $this->btn('flow_cancel', 'Cancelar')]);
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

        return new AssistantReply($text, [$this->btn('flow_send', 'Enviar agora'), $this->btn('flow_order', $sequential ? 'Todos juntos' : 'Um de cada vez'), $this->btn('flow_cancel', 'Cancelar')]);
    }

    private function send(User $user, WhatsAppFlow $flow): AssistantReply
    {
        if ((string) $flow->step === 'sending') {
            return new AssistantReply('Este envio já foi iniciado. Aguarde a confirmação.');
        }
        if ((string) $flow->step !== 'review') {
            return $this->prompt($flow);
        }
        // Reivindica o envio (e renova o prazo do fluxo): um segundo toque no botão não envia de novo.
        $claimed = WhatsAppFlow::query()->whereKey($flow->getKey())->where('step', 'review')
            ->update(['step' => 'sending', 'expires_at' => now()->addMinutes(FlowStore::TTL_MINUTES), 'updated_at' => now()]);
        if ($claimed === 0) {
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
                $this->envelopes->applyDelivery($locked->refresh(), ['channels' => ['email']]); // a conversa promete e-mail, mesmo que o rascunho tenha sido mexido no site
                $this->envelopes->assertSendable($locked->refresh(), true);
                $this->envelopes->send($locked, new RequestContext(null, 'WhatsApp'));
            });
        } catch (FlowException $e) {
            if ($e->reason === 'not_draft') {
                $this->store->clear($user);

                return new AssistantReply('Este documento já foi enviado.');
            }

            return $this->backToReview($flow, 'Não consegui enviar: '.$e->getMessage());
        } catch (Throwable $e) {
            report($e);
            // Se o envio já saiu (falha depois de gravar), segue para avisar os signatários; senão volta para a revisão.
            if (Envelope::query()->whereKey($envelope->getKey())->value('status') !== EnvelopeStatus::Sent->value) {
                return $this->backToReview($flow, 'Não consegui enviar agora (erro temporário). Toque em Enviar agora para tentar de novo.');
            }
        }

        $envelope->refresh();
        try {
            $failed = $this->notifier->inviteActive($envelope);
        } catch (Throwable $e) {
            report($e);
            $failed = array_values(array_filter($envelope->signers()->pluck('email')->all()));
        }
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

    private function backToReview(WhatsAppFlow $flow, string $message): AssistantReply
    {
        $flow = $this->store->advance($flow->refresh(), 'review');

        return new AssistantReply($message."\nO rascunho continua salvo.", $this->review($flow->refresh())->buttons);
    }

    // ---------------------------------------------------------------- cancelar e apoio

    private function cancel(User $user, WhatsAppFlow $flow): AssistantReply
    {
        $this->discardDraft($user, $flow);
        $this->store->clear($user);

        return new AssistantReply('Cancelado. O rascunho foi para a lixeira. Quando quiser, mande outro arquivo.');
    }

    /** Manda o rascunho (só se ainda for rascunho) para a lixeira, com a linha travada: não corre com um envio. */
    private function discardDraft(User $user, WhatsAppFlow $flow): void
    {
        DB::transaction(function () use ($user, $flow): void {
            $envelope = Envelope::query()->where('tenant_id', $user->tenant_id)->where('created_by', $user->getKey())->whereKey($flow->data['envelope_id'] ?? '')->lockForUpdate()->first();
            if ($envelope !== null && $envelope->status === EnvelopeStatus::Draft && ! $envelope->isTrashed()) {
                app(TrashService::class)->trash($envelope);
            }
        });
    }

    private function prompt(WhatsAppFlow $flow): AssistantReply
    {
        $d = (array) $flow->data;

        return match ((string) $flow->step) {
            'title' => new AssistantReply('Recebi o arquivo *'.($d['filename'] ?? 'documento')."*.\nQual será o título do documento? Escreva o título ou toque em Manter o nome.\nTítulo atual: ".($d['title'] ?? ''), [$this->btn('flow_title_keep', 'Manter o nome'), $this->btn('flow_cancel', 'Cancelar')]),
            'signers' => new AssistantReply('Título: *'.($d['title'] ?? '')."*\nQuem vai assinar? Mande o nome e o e-mail de uma pessoa por mensagem. Exemplo: Maria Souza maria@email.com\nSe a pessoa já está nos seus contatos, basta o nome.", ! empty($d['signers']) ? [$this->btn('flow_done', 'Pronto'), $this->btn('flow_cancel', 'Cancelar')] : [$this->btn('flow_cancel', 'Cancelar')]),
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

    // ---------------------------------------------------------------- textos

    private function btn(string $id, string $title): array
    {
        return ['id' => $this->stamp !== null ? $id.':'.$this->stamp : $id, 'title' => $title];
    }

    private function stampOf(WhatsAppFlow $flow): ?string
    {
        $id = (string) ($flow->data['envelope_id'] ?? '');

        return $id === '' ? null : substr($id, 0, 8);
    }

    private function defaultTitle(string $filename): string
    {
        $base = (string) preg_replace('/\.(pdf|docx)$/i', '', $filename);
        $base = $this->cleanTitle(str_replace('_', ' ', $base));

        return $base !== '' ? $base : 'Documento';
    }

    /** Tira controles, marcas de direção/largura zero e os caracteres de formatação do WhatsApp (* _ ~ `), junta espaços e corta em 190. */
    private function cleanTitle(string $text): string
    {
        $t = (string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', $text);
        $t = str_replace(['*', '_', '~', '`'], '', $t);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $t)), 0, 190);
    }

    /** Nome do arquivo guardado: limpo, até 120 caracteres antes da extensão (pdf ou docx). */
    private function cleanFilename(string $original, string $ext): string
    {
        $base = (string) preg_replace('/\.(pdf|docx)$/i', '', basename(str_replace('\\', '/', $original)));
        $base = mb_substr($this->cleanTitle($base), 0, 120);

        return ($base !== '' ? $base : 'documento').'.'.$ext;
    }
}
