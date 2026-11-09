<?php

use App\Http\Controllers\Api\V1\EnvelopeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.key', 'throttle:120,1'])->group(function () {
    Route::post('envelopes', [EnvelopeController::class, 'store']);
    Route::get('envelopes/{id}', [EnvelopeController::class, 'show']);
    Route::post('envelopes/{id}/send', [EnvelopeController::class, 'send']);
    Route::post('envelopes/{id}/cancel', [EnvelopeController::class, 'cancel']);
    Route::get('envelopes/{id}/evidence', [EnvelopeController::class, 'evidence']);
    Route::get('envelopes/{id}/download', [EnvelopeController::class, 'download']);
});
