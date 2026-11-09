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

Route::middleware('auth')->post('/sair', [AuthController::class, 'logout'])->name('logout');

// Painel da plataforma (super admin)
Route::middleware(['auth', 'tenant.active', 'super.admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/clientes', [\App\Http\Controllers\Admin\TenantController::class, 'index'])->name('tenants.index');
    Route::get('/clientes/{tenant}', [\App\Http\Controllers\Admin\TenantController::class, 'show'])->name('tenants.show');
    Route::put('/clientes/{tenant}', [\App\Http\Controllers\Admin\TenantController::class, 'update'])->name('tenants.update');
    Route::post('/clientes/{tenant}/suspender', [\App\Http\Controllers\Admin\TenantController::class, 'suspend'])->name('tenants.suspend');
    Route::post('/clientes/{tenant}/reativar', [\App\Http\Controllers\Admin\TenantController::class, 'reactivate'])->name('tenants.reactivate');
    Route::get('/configuracoes', [\App\Http\Controllers\Admin\SettingsController::class, 'edit'])->name('settings');
    Route::put('/configuracoes', [\App\Http\Controllers\Admin\SettingsController::class, 'update'])->name('settings.update');
});

Route::middleware(['auth', 'tenant.active'])->group(function () {
    Route::get('/painel', [EnvelopeController::class, 'dashboard'])->name('dashboard');

    Route::get('/envelopes/novo', [EnvelopeController::class, 'create'])->name('envelopes.create');
    Route::post('/envelopes', [EnvelopeController::class, 'store'])->name('envelopes.store');
    Route::get('/envelopes/{envelope}', [EnvelopeController::class, 'show'])->name('envelopes.show');
    Route::post('/envelopes/{envelope}/enviar', [EnvelopeController::class, 'send'])->name('envelopes.send');
    Route::post('/envelopes/{envelope}/cancelar', [EnvelopeController::class, 'cancel'])->name('envelopes.cancel');
    Route::get('/envelopes/{envelope}/original', [EnvelopeController::class, 'original'])->name('envelopes.original');
    Route::get('/envelopes/{envelope}/anexos/{attachment}', [EnvelopeController::class, 'attachment'])->name('envelopes.attachment');
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
    Route::post('/selfie', [SigningController::class, 'selfie'])->middleware('throttle:8,1,sign-selfie')->name('sign.selfie');
    Route::post('/documento', [SigningController::class, 'document'])->middleware('throttle:8,1,sign-document')->name('sign.document');
    Route::post('/localizacao', [SigningController::class, 'geolocation'])->middleware('throttle:10,1,sign-geo')->name('sign.geolocation');
    Route::post('/assinar', [SigningController::class, 'sign'])->name('sign.submit');
    Route::post('/recusar', [SigningController::class, 'refuse'])->name('sign.refuse');
});

// Verificação pública
Route::get('/verificar', [VerificationController::class, 'index'])->name('verify.index');
Route::post('/verificar', [VerificationController::class, 'lookup'])->middleware('throttle:20,1,verify-lookup')->name('verify.lookup');
Route::get('/verificar/{code}', [VerificationController::class, 'show'])->middleware('throttle:60,1,verify-show')->name('verify.show');
Route::post('/verificar/{code}', [VerificationController::class, 'check'])->middleware('throttle:20,1,verify-check')->name('verify.check');
