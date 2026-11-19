<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Models\Envelope;
use App\Models\User;

/** Situação de um documento: status, quem já assinou, quem falta e o vencimento do contrato. */
final class DocumentStatusTool extends ReadOnlyTool
{
    public function name(): string
    {
        return 'document_status';
    }

    public function description(): string
    {
        return 'Mostra a situação de um documento da empresa (status, quem assinou e quem falta, vencimento). "document" é o identificador ou parte do título; se houver vários parecidos, devolve as opções para o usuário escolher.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['document' => ['type' => 'string', 'maxLength' => 100]], 'required' => ['document']];
    }

    public function run(User $user, array $args): ToolResult
    {
        $found = $this->resolve($user, (string) ($args['document'] ?? ''));
        if ($found['doc'] === null) {
            return $found['options']->isNotEmpty() ? $this->ask($found['options']) : $this->notFound();
        }

        return $this->describe($found['doc']);
    }

    private function describe(Envelope $e): ToolResult
    {
        $signers = $e->signers()->whereIn('role', Envelope::PARTICIPANT_ROLES)->orderBy('position')->get();
        $signed = $signers->where('status.value', 'signed')->count();
        $missing = $signers->filter(fn ($s) => ! in_array($s->status->value, ['signed', 'refused'], true))->map(fn ($s) => $this->clean($s->name, 40));

        $lines = [$this->quote($e->title).' — '.$this->statusLabel($e->status).'.'];
        if ($signers->isNotEmpty()) {
            $lines[] = "Assinaturas: {$signed} de {$signers->count()} assinaram.".($missing->isNotEmpty() ? ' Faltam: '.$missing->implode(', ').'.' : '');
        }
        $lines[] = 'Criado em '.$this->date($e->created_at).($e->sent_at ? ', enviado em '.$this->date($e->sent_at) : '').($e->completed_at ? ', concluído em '.$this->date($e->completed_at) : '').'.';
        if ($e->contract_expires_on !== null) {
            $lines[] = 'Vencimento do contrato: '.$e->contract_expires_on->format('d/m/Y').'.';
        }

        return new ToolResult(implode("\n", $lines), ['id' => $e->getKey(), 'status' => $e->status->value]);
    }
}
