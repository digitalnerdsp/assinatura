<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Evidence\EvidenceLogger;
use App\Models\Envelope;
use App\Models\User;

/** Verifica um código de verificação com a mesma informação da página pública: sem dados pessoais além do que ela já mostra. */
final class VerifyCodeTool extends ReadOnlyTool
{
    public function __construct(private readonly EvidenceLogger $evidence)
    {
    }

    public function name(): string
    {
        return 'verify_code';
    }

    public function description(): string
    {
        return 'Verifica o código de verificação impresso num documento assinado e informa o status, o emissor e se a trilha de auditoria está íntegra.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'maxLength' => 40]], 'required' => ['code']];
    }

    public function run(User $user, array $args): ToolResult
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($args['code'] ?? '')) ?? '');
        $e = strlen($code) >= 6 && strlen($code) <= 32 ? Envelope::query()->with('tenant')->where('verification_code', $code)->first() : null;
        if ($e === null) {
            return new ToolResult('Código não encontrado. Confira os caracteres impressos no documento.');
        }

        $signers = $e->signers()->whereIn('role', Envelope::PARTICIPANT_ROLES)->get();
        $lines = [
            $this->quote($e->title).' — emitido por '.$this->clean($e->tenant->name, 80).'.',
            'Situação: '.$this->statusLabel($e->status).($signers->isNotEmpty() ? ", {$signers->where('status.value', 'signed')->count()} de {$signers->count()} assinaram." : '.'),
        ];
        if ($e->sealed_at !== null) {
            $lines[] = 'Selado em '.$this->date($e->sealed_at).'.';
        }
        $lines[] = $this->evidence->isIntact($e) ? 'A trilha de auditoria está íntegra.' : 'Atenção: a trilha de auditoria está inconsistente.';

        return new ToolResult(implode("\n", $lines));
    }
}
