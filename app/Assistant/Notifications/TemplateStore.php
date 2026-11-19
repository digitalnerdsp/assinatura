<?php

declare(strict_types=1);

namespace App\Assistant\Notifications;

use App\Assistant\Channel\MetaWhatsAppClient;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Collection;

/** Os modelos de mensagem: criação do rascunho padrão, edição, envio para aprovação na Meta e sincronização do status. */
final class TemplateStore
{
    private const STATUS_MAP = [
        'APPROVED' => 'aprovado', 'PENDING' => 'enviado', 'IN_APPEAL' => 'enviado',
        'REJECTED' => 'rejeitado', 'PAUSED' => 'pausado', 'DISABLED' => 'pausado',
    ];

    public function __construct(private readonly MetaWhatsAppClient $client)
    {
    }

    /** @return Collection<string,WhatsAppTemplate> chave da situação => modelo (cria os rascunhos que faltam) */
    public function all(): Collection
    {
        $out = collect();
        foreach (array_keys(NotificationCatalog::all()) as $key) {
            $out[$key] = $this->find($key);
        }

        return $out;
    }

    public function find(string $key): WhatsAppTemplate
    {
        $def = NotificationCatalog::all()[$key] ?? null;
        abort_if($def === null, 404);

        return WhatsAppTemplate::query()->firstOrCreate(['key' => $key], ['meta_name' => $def['meta_name'], 'body' => $def['body'], 'language' => 'pt_BR', 'category' => 'utility'])->refresh();
    }

    /** O modelo só serve para enviar quando está aprovado pela Meta e ligado. */
    public function usable(string $key): ?WhatsAppTemplate
    {
        $t = WhatsAppTemplate::query()->where('key', $key)->first();

        return $t !== null && $t->enabled && $t->status === 'aprovado' ? $t : null;
    }

    /** @param array{meta_name:string,language:string,body:string,enabled?:bool} $data */
    public function update(string $key, array $data): WhatsAppTemplate
    {
        $t = $this->find($key);
        $changed = $t->meta_name !== $data['meta_name'] || $t->language !== $data['language'] || trim($t->body) !== trim($data['body']);
        $t->fill(['meta_name' => $data['meta_name'], 'language' => $data['language'], 'body' => trim($data['body'])]);

        if ($changed && $t->status !== 'rascunho') {
            // O que a Meta aprovou foi outro texto: volta a rascunho e precisa ser enviado de novo.
            $t->status = 'rascunho';
            $t->meta_template_id = null;
            $t->rejected_reason = null;
            $t->enabled = false;
        } else {
            $t->enabled = (bool) ($data['enabled'] ?? false) && $t->status === 'aprovado';
        }
        $t->save();

        return $t;
    }

    /** Envia o modelo para a Meta aprovar. Lança MetaException se faltar configuração ou a Meta recusar. */
    public function submit(WhatsAppTemplate $t): void
    {
        if (! in_array($t->status, ['rascunho', 'rejeitado'], true)) {
            throw new \App\Assistant\Channel\MetaException('Só modelo em rascunho ou rejeitado vai para aprovação (este está '.$t->status.').');
        }
        $def = NotificationCatalog::all()[$t->key];
        $result =$this->client->createTemplate($t->meta_name, $t->language, $t->category, $t->body, $def['example']);

        $t->forceFill(['status' => 'enviado', 'meta_template_id' => $result['id'], 'rejected_reason' => null, 'enabled' => false])->save();
    }

    /** Lê o status de cada modelo enviado. @return int quantos modelos mudaram */
    public function sync(): int
    {
        $byName = [];
        foreach ($this->client->listTemplates() as $r) {
            if (isset($r['name'], $r['status'])) {
                $byName[(string) $r['name']] = $r;
            }
        }

        $n = 0;
        foreach (WhatsAppTemplate::query()->where('status', '!=', 'rascunho')->get() as $t) {
            $r = $byName[$t->meta_name] ?? null;
            if ($r === null) {
                continue;
            }
            $status = self::STATUS_MAP[strtoupper((string) $r['status'])] ?? $t->status;
            $category = isset($r['category']) ? strtolower(mb_substr((string) $r['category'], 0, 20)) : $t->category;
            $reason = $status === 'rejeitado' ? mb_substr((string) ($r['rejected_reason'] ?? ''), 0, 190) : null;

            $t->fill(['status' => $status, 'category' => $category, 'rejected_reason' => $reason]);
            if ($status !== 'aprovado') {
                $t->enabled = false;
            }
            if ($t->isDirty()) {
                $t->save();
                $n++;
            }
        }

        return $n;
    }
}
