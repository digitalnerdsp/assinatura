<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\DocumentTemplate;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Signer;
use App\Models\Tag;
use App\Models\WebhookEndpoint;

/** Formato único das respostas da API v1. */
final class Presenter
{
    /** @return array<string,mixed> */
    public static function envelope(Envelope $e): array
    {
        $e->loadMissing(['signers', 'document', 'folder', 'tags']);

        return [
            'id' => $e->getKey(),
            'external_id' => $e->external_id,
            'metadata' => $e->metadata,
            'title' => $e->title,
            'status' => $e->status->value,
            'signing_order' => $e->signing_order->value,
            'required_level' => $e->required_level,
            'verifications' => [
                'otp' => $e->require_otp, 'selfie' => $e->require_selfie,
                'document' => $e->require_document, 'geolocation' => $e->require_geolocation,
            ],
            'channels' => $e->channels(),
            'validity_days' => $e->validity_days,
            'reminder_every_days' => $e->reminder_every_days,
            'folder' => $e->folder ? ['id' => $e->folder->getKey(), 'name' => $e->folder->name] : null,
            'tags' => $e->tags->map(fn (Tag $t) => ['id' => $t->getKey(), 'name' => $t->name, 'color' => $t->color])->all(),
            'document' => ['name' => $e->document->name, 'size' => $e->document->size, 'sha256' => $e->document->sha256],
            'verification_code' => $e->verification_code,
            'verification_url' => route('verify.show', $e->verification_code),
            'created_at' => $e->created_at?->toIso8601String(),
            'sent_at' => $e->sent_at?->toIso8601String(),
            'expires_at' => $e->expires_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
            'final_sha256' => $e->final_sha256,
            'signers' => $e->signers->map(fn (Signer $s) => self::signer($s, $e))->all(),
        ];
    }

    /** @return array<string,mixed> */
    public static function signer(Signer $s, Envelope $e): array
    {
        return [
            'id' => $s->getKey(),
            'name' => $s->name,
            'email' => $s->email,
            'phone' => $s->phone,
            'role' => $s->role,
            'is_sender' => (bool) $s->is_sender,
            'signed_via' => $s->signed_via,
            'position' => $s->position,
            'status' => $s->status->value,
            'viewed_at' => $s->viewed_at?->toIso8601String(),
            'signed_at' => $s->signed_at?->toIso8601String(),
            'refused_reason' => $s->refused_reason,
            'reminders_count' => $s->reminders_count,
            'sign_url' => $e->status->isOpenForSigning() ? $s->signUrl() : null,
        ];
    }

    /** @return array<string,mixed> */
    public static function template(DocumentTemplate $t, bool $withBody = false): array
    {
        return [
            'id' => $t->getKey(),
            'name' => $t->name,
            'variables' => $t->variables(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ] + ($withBody ? ['body' => $t->body] : []);
    }

    /** @return array<string,mixed> */
    public static function webhook(WebhookEndpoint $w): array
    {
        return [
            'id' => $w->getKey(),
            'url' => $w->url,
            'events' => $w->events,
            'active' => $w->active,
            'created_at' => $w->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public static function folder(Folder $f, ?array $paths = null): array
    {
        return [
            'id' => $f->getKey(),
            'name' => $f->name,
            'parent_id' => $f->parent_id,
            'path' => str_replace(' › ', ' / ', $paths[$f->getKey()] ?? $f->name),
        ];
    }

    /** @return array<string,mixed> */
    public static function tag(Tag $t): array
    {
        return ['id' => $t->getKey(), 'name' => $t->name, 'color' => $t->color];
    }
}
