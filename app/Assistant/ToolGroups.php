<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Models\Tenant;

/** Grupos de ações que a empresa pode liberar ou não ao assistente. */
final class ToolGroups
{
    public const GROUPS = [
        'consult' => ['label' => 'Consultar documentos (listar, ver status, quem falta assinar, verificar código)', 'tools' => ['list_documents', 'document_status', 'pending_signers', 'verify_code']],
        'send_pdf' => ['label' => 'Enviar o PDF assinado para o WhatsApp do usuário', 'tools' => ['send_signed_pdf']],
        'contracts' => ['label' => 'Consultar contratos a vencer', 'tools' => ['list_contracts_due']],
        'send_documents' => ['label' => 'Enviar documentos para assinatura (o usuário manda o arquivo no WhatsApp e confirma os signatários)', 'tools' => []],
    ];

    /** Nomes de ferramenta permitidos para a empresa; null = sem restrição (todas as registradas). @return list<string>|null */
    public static function allowedNames(Tenant $tenant): ?array
    {
        $groups = $tenant->assistant_tools;
        if ($groups === null) {
            return null;
        }

        $names = [];
        foreach ((array) $groups as $g) {
            $names = array_merge($names, self::GROUPS[$g]['tools'] ?? []);
        }

        return array_values(array_unique($names));
    }

    /** A empresa liberou este grupo? Sem restrição configurada, todos estão ligados. */
    public static function allows(Tenant $tenant, string $group): bool
    {
        return in_array($group, self::enabled($tenant), true);
    }

    /** Grupos hoje ligados. @return list<string> */
    public static function enabled(Tenant $tenant): array
    {
        return $tenant->assistant_tools === null ? array_keys(self::GROUPS) : array_values(array_intersect(array_keys(self::GROUPS), (array) $tenant->assistant_tools));
    }
}
