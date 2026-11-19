<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\User;

/** Quem ainda falta assinar: num documento ou, sem documento, em todos os que estão em andamento. */
final class PendingSignersTool extends ReadOnlyTool
{
    public function name(): string
    {
        return 'pending_signers';
    }

    public function description(): string
    {
        return 'Diz quem ainda falta assinar. Com "document" (identificador ou parte do título), responde sobre aquele documento; sem ele, resume todos os documentos aguardando assinatura.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['document' => ['type' => 'string', 'maxLength' => 100]]];
    }

    public function run(User $user, array $args): ToolResult
    {
        if (! empty($args['document'])) {
            $found = $this->resolve($user, (string) $args['document']);
            if ($found['doc'] === null) {
                return $found['options']->isNotEmpty() ? $this->ask($found['options']) : $this->notFound();
            }
            $names = $this->missing($found['doc']);

            return new ToolResult($names === []
                ? 'Ninguém falta assinar em '.$this->quote($found['doc']->title).'.'
                : 'Faltam assinar em '.$this->quote($found['doc']->title).': '.implode(', ', $names).'.');
        }

        $docs = $this->documents($user)->whereIn('status', [EnvelopeStatus::Sent->value, EnvelopeStatus::InProgress->value])->orderByDesc('created_at')->limit(10)->get();
        $lines = $docs->map(fn (Envelope $e) => ['e' => $e, 'names' => $this->missing($e)])->filter(fn ($x) => $x['names'] !== [])
            ->map(fn ($x) => '• '.$this->quote($x['e']->title).' — falta: '.implode(', ', $x['names']))->values();

        return $lines->isEmpty()
            ? new ToolResult('Nenhum documento aguardando assinatura no momento.')
            : new ToolResult("Documentos aguardando assinatura:\n".$lines->implode("\n"));
    }

    /** @return list<string> */
    private function missing(Envelope $e): array
    {
        return $e->signers()->whereIn('role', Envelope::PARTICIPANT_ROLES)->whereNotIn('status', ['signed', 'refused'])->orderBy('position')->get()
            ->map(fn ($s) => $this->clean($s->name, 40))->values()->all();
    }
}
