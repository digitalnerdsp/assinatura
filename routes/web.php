<?php

use App\Http\Controllers\Public\SigningController;
use App\Http\Controllers\Public\VerificationController;
use App\Http\Controllers\Web\ApiKeyController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\EnvelopeController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/entrar', [AuthController::class, 'login'])->middleware('throttle:10,1,login');
    Route::get('/cadastro', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/cadastro', [AuthController::class, 'register'])->middleware('throttle:10,1,auth');
});

Route::middleware('auth')->group(function () {
    Route::post('/sair', [AuthController::class, 'logout'])->name('logout');
    Route::get('/painel', [EnvelopeController::class, 'dashboard'])->name('dashboard');

    Route::get('/envelopes/novo', [EnvelopeController::class, 'create'])->name('envelopes.create');
    Route::post('/envelopes', [EnvelopeController::class, 'store'])->name('envelopes.store');
    Route::get('/envelopes/{envelope}', [EnvelopeController::class, 'show'])->name('envelopes.show');
    Route::post('/envelopes/{envelope}/enviar', [EnvelopeController::class, 'send'])->name('envelopes.send');
    Route::post('/envelopes/{envelope}/cancelar', [EnvelopeController::class, 'cancel'])->name('envelopes.cancel');
    Route::get('/envelopes/{envelope}/original', [EnvelopeController::class, 'original'])->name('envelopes.original');
    Route::get('/envelopes/{envelope}/selado', [EnvelopeController::class, 'final'])->name('envelopes.final');

    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
});

// Assinatura pelo signatário (sem login; o token é a credencial)
Route::prefix('assinar/{token}')->middleware('throttle:60,1,sign')->group(function () {
    Route::get('/', [SigningController::class, 'show'])->name('sign.show');
    Route::get('/pdf', [SigningController::class, 'pdf'])->name('sign.pdf');
    Route::post('/codigo', [SigningController::class, 'requestOtp'])->middleware('throttle:5,1,sign-otp')->name('sign.otp');
    Route::post('/codigo/confirmar', [SigningController::class, 'verifyOtp'])->middleware('throttle:10,1,sign-otp-verify')->name('sign.otp.verify');
    Route::post('/assinar', [SigningController::class, 'sign'])->name('sign.submit');
    Route::post('/recusar', [SigningController::class, 'refuse'])->name('sign.refuse');
});

// Verificação pública
Route::get('/verificar', [VerificationController::class, 'index'])->name('verify.index');
Route::post('/verificar', [VerificationController::class, 'lookup'])->middleware('throttle:20,1,verify-lookup')->name('verify.lookup');
Route::get('/verificar/{code}', [VerificationController::class, 'show'])->middleware('throttle:60,1,verify-show')->name('verify.show');
Route::post('/verificar/{code}', [VerificationController::class, 'check'])->middleware('throttle:20,1,verify-check')->name('verify.check');
