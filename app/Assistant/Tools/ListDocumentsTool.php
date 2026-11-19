<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Enums\EnvelopeStatus;
use App\Models\User;

/** Lista documentos da empresa, do mais novo ao mais antigo, com filtro por situação e por parte do título. */
final class ListDocumentsTool extends ReadOnlyTool
{
    private const GROUPS = [
        'pending' => [EnvelopeStatus::Sent, EnvelopeStatus::InProgress, EnvelopeStatus::Sealing],
        'completed' => [EnvelopeStatus::Completed],
        'refused' => [EnvelopeStatus::Refused],
        'expired' => [EnvelopeStatus::Expired],
        'cancelled' => [EnvelopeStatus::Cancelled],
        'draft' => [EnvelopeStatus::Draft],
    ];

    public function name(): string
    {
        return 'list_documents';
    }

    public function description(): string
    {
        return 'Lista os documentos da empresa do usuário (mais novos primeiro). Use status para filtrar por situação (pending = aguardando assinaturas) e query para buscar por parte do título.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => array_keys(self::GROUPS)],
            'query' => ['type' => 'string', 'maxLength' => 100],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
        ]];
    }

    public function run(User $user, array $args): ToolResult
    {
        $limit = max(1, min(10, (int) ($args['limit'] ?? 5)));
        $query = $this->documents($user);

        if (isset($args['status'])) {
            $query->whereIn('status', array_map(fn (EnvelopeStatus $s) => $s->value, self::GROUPS[$args['status']] ?? []));
        } else {
            $query->where('status', '!=', EnvelopeStatus::Draft->value);
        }
        if (! empty($args['query'])) {
            $query->whereRaw('title ILIKE ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr((string) $args['query'], 0, 100)).'%']);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            return new ToolResult('Nenhum documento encontrado com esse filtro.');
        }
        $docs = $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        $lines = $docs->values()->map(fn ($e, int $i) => ($i + 1).'. '.$this->quote($e->title).' — '.$this->statusLabel($e->status).', criado em '.$this->date($e->created_at))->implode("\n");
        $head = $total > $docs->count() ? "Encontrei {$total} documentos (mostrando {$docs->count()}):" : "Encontrei {$total} documento".($total === 1 ? '' : 's').':';

        return new ToolResult($head."\n".$lines, ['count' => $total], ['body' => 'Seus documentos', 'button' => 'Ver documentos', 'rows' => $this->rows($docs)]);
    }
}
