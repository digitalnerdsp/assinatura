<?php

declare(strict_types=1);

namespace App\Contacts;

use App\Envelopes\Exceptions\FlowException;
use App\Messaging\Phone;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Importar e exportar contatos em CSV. Aceita ";" ou "," e cabeçalhos em português ou inglês; exporta com ";" e BOM
 * (abre direto no Excel em português) e protege contra fórmulas (=, +, -, @ no início viram texto).
 */
final class ContactCsv
{
    public const MAX_ROWS = 5000;

    private const HEADERS = [
        'name' => ['nome', 'name'],
        'email' => ['email', 'e-mail', 'mail'],
        'phone' => ['telefone', 'phone', 'celular', 'whatsapp', 'fone'],
        'document' => ['cpf', 'cnpj', 'cpf/cnpj', 'cpfcnpj', 'documento', 'document'],
        'company' => ['empresa', 'company', 'organizacao'],
        'notes' => ['observacao', 'observacoes', 'obs', 'notes', 'notas'],
    ];

    /** @return array{created:int,updated:int,skipped:int,errors:list<string>} */
    public function import(Tenant $tenant, string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, 0, $delimiter, '"', '');
        $columns = $this->mapHeader($header ?: []);
        if (! isset($columns['email'])) {
            fclose($stream);
            throw FlowException::because('csv_without_email', 'O arquivo precisa de uma coluna de e-mail (por exemplo "E-mail").');
        }

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $line = 1;
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($row === [null] || $row === ['']) {
                continue; // linha em branco
            }
            if ($line - 1 > self::MAX_ROWS) {
                $result['errors'][] = 'Só as primeiras '.self::MAX_ROWS.' linhas foram lidas.';
                break;
            }

            $get = fn (string $field) => isset($columns[$field]) ? trim((string) ($row[$columns[$field]] ?? '')) : '';
            $email = mb_strtolower($get('email'));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                $result['skipped']++;
                $result['errors'][] = "Linha {$line}: e-mail ausente ou inválido.";

                continue;
            }

            $values = [
                'name' => mb_substr($get('name') !== '' ? $get('name') : $email, 0, 150),
                'phone' => $get('phone') !== '' ? Phone::normalize($get('phone')) : null,
                'document' => $this->document($get('document')),
                'company' => $get('company') !== '' ? mb_substr($get('company'), 0, 150) : null,
                'notes' => $get('notes') !== '' ? mb_substr($get('notes'), 0, 1000) : null,
            ];

            $existing = Contact::query()->where('tenant_id', $tenant->getKey())->whereRaw('lower(email) = ?', [$email])->first();
            if ($existing !== null) {
                // não sobrescreve o que já existe: só preenche campos vazios
                $fill = [];
                foreach (['phone', 'document', 'company', 'notes'] as $field) {
                    if ($existing->{$field} === null && $values[$field] !== null) {
                        $fill[$field] = $values[$field];
                    }
                }
                if ($fill === []) {
                    $result['skipped']++;
                } else {
                    $existing->update($fill);
                    $result['updated']++;
                }

                continue;
            }

            try {
                Contact::query()->create(['tenant_id' => $tenant->getKey(), 'email' => $email] + $values);
                $result['created']++;
            } catch (UniqueConstraintViolationException) {
                $result['skipped']++;
            }
        }

        fclose($stream);

        return $result;
    }

    public function export(Tenant $tenant): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Nome', 'E-mail', 'Telefone', 'CPF/CNPJ', 'Empresa', 'Observação', 'Etiquetas'], ';', '"', '');

        Contact::query()->where('tenant_id', $tenant->getKey())->with('tags')->orderByRaw('lower(name)')
            ->each(function (Contact $c) use ($out): void {
                fputcsv($out, array_map([self::class, 'safeCell'], [
                    $c->name, $c->email, $c->phone, $c->document, $c->company, $c->notes, $c->tags->pluck('name')->implode(', '),
                ]), ';', '"', '');
            });

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF".$csv;
    }

    /** Célula que começa com = + - @ (ou tab/CR) vira texto com um apóstrofo, exceto número de telefone (+ e dígitos). */
    public static function safeCell(mixed $value): string
    {
        $value = (string) $value;
        if ($value === '' || preg_match('/^\+?[\d\s().-]+$/', $value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** @param list<?string> $header @return array<string,int> campo => índice */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $raw) {
            $name = $this->normalize((string) $raw);
            foreach (self::HEADERS as $field => $aliases) {
                if (! isset($map[$field]) && in_array($name, $aliases, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return trim($value);
    }

    private function document(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw);

        return in_array(strlen($digits), [11, 14], true) ? $digits : null;
    }
}
