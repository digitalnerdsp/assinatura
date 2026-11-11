<?php

use App\Http\Controllers\Api\V1\DocsController;
use App\Http\Controllers\Api\V1\EnvelopeController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\VerifyController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

// Públicas (sem chave): documentação e verificação por código.
Route::prefix('v1')->group(function () {
    Route::get('openapi.json', [DocsController::class, 'spec'])->middleware('throttle:60,1,api-docs');
    Route::get('verify/{code}', VerifyController::class)->middleware('throttle:60,1,api-verify');
});

// Autenticadas por chave: "Authorization: Bearer sk_live_...". Chaves somente leitura aceitam só GET.
Route::prefix('v1')->middleware(['api.key', 'throttle:120,1'])->group(function () {
    Route::get('me', MeController::class);

    // Envelopes. {id} aceita o id da plataforma ou o seu external_id.
    Route::get('envelopes', [EnvelopeController::class, 'index']);
    Route::post('envelopes', [EnvelopeController::class, 'store'])->middleware('idempotent');
    Route::get('envelopes/{id}', [EnvelopeController::class, 'show']);
    Route::post('envelopes/{id}/send', [EnvelopeController::class, 'send']);
    Route::post('envelopes/{id}/cancel', [EnvelopeController::class, 'cancel']);
    Route::post('envelopes/{id}/reseal', [EnvelopeController::class, 'reseal']);
    Route::get('envelopes/{id}/evidence', [EnvelopeController::class, 'evidence']);
    Route::get('envelopes/{id}/download', [EnvelopeController::class, 'download']);
    Route::get('envelopes/{id}/original', [EnvelopeController::class, 'original']);
    Route::post('envelopes/{id}/signers/{signer}/resend', [EnvelopeController::class, 'resend']);
    Route::post('envelopes/{id}/signers/{signer}/new-link', [EnvelopeController::class, 'newLink']);

    // Modelos
    Route::get('templates', [TemplateController::class, 'index']);
    Route::post('templates', [TemplateController::class, 'store']);
    Route::get('templates/{id}', [TemplateController::class, 'show']);
    Route::put('templates/{id}', [TemplateController::class, 'update']);
    Route::delete('templates/{id}', [TemplateController::class, 'destroy']);
    Route::post('templates/{id}/envelopes', [TemplateController::class, 'generate'])->middleware('idempotent');

    // Webhooks
    Route::get('webhooks', [WebhookController::class, 'index']);
    Route::post('webhooks', [WebhookController::class, 'store']);
    Route::patch('webhooks/{id}', [WebhookController::class, 'update']);
    Route::delete('webhooks/{id}', [WebhookController::class, 'destroy']);
    Route::post('webhooks/{id}/test', [WebhookController::class, 'test']);
    Route::get('webhooks/{id}/deliveries', [WebhookController::class, 'deliveries']);

    // Pastas e etiquetas
    Route::get('folders', [OrganizationController::class, 'folders']);
    Route::post('folders', [OrganizationController::class, 'storeFolder']);
    Route::delete('folders/{id}', [OrganizationController::class, 'destroyFolder']);
    Route::get('tags', [OrganizationController::class, 'tags']);
    Route::post('tags', [OrganizationController::class, 'storeTag']);
    Route::delete('tags/{id}', [OrganizationController::class, 'destroyTag']);
});
