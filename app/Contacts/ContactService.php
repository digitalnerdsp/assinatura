<?php

declare(strict_types=1);

namespace App\Contacts;

use App\Envelopes\Exceptions\FlowException;
use App\Messaging\Phone;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contatos da empresa: e-mail único por empresa (sem diferenciar maiúsculas), telefone em E.164 e CPF/CNPJ só
 * com dígitos. Signatários e testemunhas usados num envelope entram sozinhos, sem sobrescrever o que já foi editado.
 */
final class ContactService
{
    /**
     * @param  array{name:string,email:string,phone?:?string,document?:?string,company?:?string,notes?:?string,tags?:list<string>}  $data
     */
    public function save(Tenant $tenant, array $data, ?Contact $contact = null): Contact
    {
        $email = mb_strtolower(trim($data['email']));
        $phone = $this->phone($data['phone'] ?? null);
        $document = $this->document($data['document'] ?? null);

        $taken = Contact::query()->where('tenant_id', $tenant->getKey())->whereRaw('lower(email) = ?', [$email])
            ->when($contact, fn ($q) => $q->where('id', '!=', $contact->getKey()))->exists();
        if ($taken) {
            throw FlowException::because('contact_email_taken', 'Já existe um contato com este e-mail.');
        }

        return DB::transaction(function () use ($tenant, $data, $contact, $email, $phone, $document): Contact {
            $contact ??= new Contact(['tenant_id' => $tenant->getKey()]);
            $contact->fill([
                'name' => trim($data['name']),
                'email' => $email,
                'phone' => $phone,
                'document' => $document,
                'company' => isset($data['company']) && trim((string) $data['company']) !== '' ? trim($data['company']) : null,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim($data['notes']) : null,
            ]);

            try {
                $contact->save();
            } catch (UniqueConstraintViolationException) {
                throw FlowException::because('contact_email_taken', 'Já existe um contato com este e-mail.');
            }

            if (array_key_exists('tags', $data)) {
                $contact->tags()->sync(Tag::query()->where('tenant_id', $tenant->getKey())->whereIn('id', $data['tags'] ?? [])->pluck('id')->all());
            }

            return $contact;
        });
    }

    public function delete(Contact $contact): void
    {
        $contact->delete(); // LGPD: o envelope já criado guarda os dados necessários ao documento
    }

    /**
     * Guarda signatários/testemunhas como contatos: cria os novos e só preenche campos vazios dos existentes.
     *
     * @param  list<array<string,mixed>>  $people
     */
    public function rememberFromSigners(Tenant $tenant, array $people): void
    {
        foreach ($people as $person) {
            $email = mb_strtolower(trim((string) ($person['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $phone = Phone::normalize($person['phone'] ?? null);
            $document = $this->documentOrNull($person['cpf'] ?? ($person['document'] ?? null));
            $existing = Contact::query()->where('tenant_id', $tenant->getKey())->whereRaw('lower(email) = ?', [$email])->first();

            if ($existing === null) {
                try {
                    Contact::query()->create([
                        'tenant_id' => $tenant->getKey(), 'name' => mb_substr(trim((string) ($person['name'] ?? $email)), 0, 150),
                        'email' => $email, 'phone' => $phone, 'document' => $document,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // outro envio criou o mesmo contato ao mesmo tempo: nada a fazer
                }

                continue;
            }

            $fill = array_filter([
                'phone' => $existing->phone === null ? $phone : null,
                'document' => $existing->document === null ? $document : null,
            ], fn ($v) => $v !== null);
            if ($fill !== []) {
                $existing->update($fill);
            }
        }
    }

    /** @return Collection<int,Contact> */
    public function search(Tenant $tenant, string $q, int $limit = 8): Collection
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($q))).'%';

        return Contact::query()->where('tenant_id', $tenant->getKey())
            ->where(fn ($w) => $w->whereRaw('lower(name) LIKE ?', [$like])->orWhereRaw('lower(email) LIKE ?', [$like])->orWhereRaw('lower(company) LIKE ?', [$like]))
            ->orderByRaw('lower(name)')->limit($limit)->get();
    }

    private function phone(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $normalized = Phone::normalize($raw);
        if ($normalized === null) {
            throw FlowException::because('invalid_phone', 'Telefone inválido. Use DDD e número, por exemplo (11) 99999-8888.');
        }

        return $normalized;
    }

    private function document(?string $raw): ?string
    {
        $digits = $this->documentOrNull($raw);
        if ($raw !== null && trim($raw) !== '' && $digits === null) {
            throw FlowException::because('invalid_document', 'CPF ou CNPJ inválido: use 11 ou 14 dígitos.');
        }

        return $digits;
    }

    private function documentOrNull(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        return in_array(strlen($digits), [11, 14], true) ? $digits : null;
    }
}
