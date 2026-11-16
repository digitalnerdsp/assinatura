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
