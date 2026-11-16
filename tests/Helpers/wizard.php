<?php

declare(strict_types=1);

use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\User;

// Helpers do assistente de criação de documento (carregados por tests/Pest.php).
const WZ_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

function wzOwner(string $email = 'dono@wz.test'): User
{
    return User::create(['tenant_id' => Tenant::create(['name' => 'Empresa '.$email])->id, 'name' => 'Dono', 'email' => $email, 'password' => 'senha-muito-forte', 'role' => 'owner']);
}

function wzDraft(User $u, string $title = 'Contrato', string $pdf = WZ_PDF): Envelope
{
    return app(EnvelopeService::class)->createDraft($u->tenant, $u, $pdf, 'contrato.pdf', $title);
}

function wzPerson(string $name, ?string $email = null, string $role = 'signer', array $extra = []): array
{
    return ['name' => $name, 'email' => $email, 'role' => $role] + $extra;
}

function wzSync(Envelope $e, User $u, array $people, bool $sender = false, SigningOrder $order = SigningOrder::Parallel, array $verif = []): void
{
    app(EnvelopeService::class)->syncPeople($e, $u, $people, $sender, $order, $verif);
}

function wzField(string $signerId, array $over = []): array
{
    return $over + ['signer_id' => $signerId, 'type' => 'signature', 'page' => 1, 'x' => 0.2, 'y' => 0.3, 'width' => 0.3, 'height' => 0.07];
}

/**
 * Cria um rascunho pelas telas do assistente (passos 1 a 3 e, se vier `delivery`, o 4), com o usuário já logado no teste.
 * Opções: title, people, order, file, folder_id, sender (0|1), verif, fields [['signer' => índice|'sender', 'page', 'x', 'y']], delivery.
 */
function wzWeb(array $o = []): Envelope
{
    $o += ['title' => 'Documento', 'people' => [['name' => 'Ana', 'email' => 'ana@ex.com']], 'order' => 'parallel', 'file' => null, 'folder_id' => null, 'sender' => 0, 'verif' => [], 'fields' => [], 'delivery' => []];
    $file = $o['file'] ?? \Illuminate\Http\UploadedFile::fake()->createWithContent('contrato.pdf', WZ_PDF);
    $res = test()->post('/documentos', array_filter(['document' => $file, 'title' => $o['title'], 'folder_id' => $o['folder_id']], fn ($v) => $v !== null))->assertSessionHasNoErrors();
    preg_match('#/documentos/([0-9a-f-]{36})/passo/2#', (string) $res->headers->get('Location'), $m);
    $e = Envelope::query()->findOrFail($m[1]);

    test()->put("/documentos/{$e->id}/passo/2", ['sender_signs' => $o['sender'], 'signing_order' => $o['order'], 'people' => array_map(fn ($p) => $p + ['role' => 'signer'] + $o['verif'], $o['people'])])->assertSessionHasNoErrors();

    if ($o['fields'] === []) {
        test()->put("/documentos/{$e->id}/passo/3", ['skipped' => 1])->assertSessionHasNoErrors();
    } else {
        $signers = $e->signers()->orderBy('position')->get();
        $fields = array_map(function (array $f) use ($signers) {
            $s = $f['signer'] === 'sender' ? $signers->firstWhere('is_sender', true) : $signers->where('is_sender', false)->values()[$f['signer']];

            return ['signer_id' => $s->id, 'type' => 'signature', 'page' => $f['page'], 'x' => $f['x'], 'y' => $f['y'], 'width' => 0.3, 'height' => 0.07];
        }, $o['fields']);
        test()->put("/documentos/{$e->id}/passo/3", ['fields' => $fields])->assertSessionHasNoErrors();
    }

    if ($o['delivery'] !== []) {
        test()->put("/documentos/{$e->id}/passo/4", $o['delivery'])->assertSessionHasNoErrors();
    }

    return $e->fresh();
}
