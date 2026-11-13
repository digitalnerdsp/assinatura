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

// Logo da empresa (público: aparece na página de assinatura); só com o recurso no plano
Route::get('/marca/{tenant}/logo', [\App\Http\Controllers\Web\BrandController::class, 'logo'])->whereUuid('tenant')->middleware('throttle:120,1,brand-logo')->name('brand.logo');

// Convite de usuário (público; o token é a credencial)
Route::get('/convite/{token}', [\App\Http\Controllers\Web\InviteController::class, 'show'])->middleware('throttle:30,1,invite')->name('invite.show');
Route::post('/convite/{token}', [\App\Http\Controllers\Web\InviteController::class, 'accept'])->middleware('throttle:10,1,invite-accept')->name('invite.accept');

Route::middleware('auth')->post('/sair', [AuthController::class, 'logout'])->name('logout');

// Painel da plataforma: admin opera; super admin também gerencia equipe, e-mail e configurações
Route::middleware(['auth', 'tenant.active', 'platform'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/clientes', [\App\Http\Controllers\Admin\TenantController::class, 'index'])->name('tenants.index');
    Route::get('/clientes/{tenant}', [\App\Http\Controllers\Admin\TenantController::class, 'show'])->name('tenants.show');
    Route::put('/clientes/{tenant}', [\App\Http\Controllers\Admin\TenantController::class, 'update'])->name('tenants.update');
    Route::post('/clientes/{tenant}/suspender', [\App\Http\Controllers\Admin\TenantController::class, 'suspend'])->name('tenants.suspend');
    Route::post('/clientes/{tenant}/reativar', [\App\Http\Controllers\Admin\TenantController::class, 'reactivate'])->name('tenants.reactivate');
    Route::get('/assinaturas', [\App\Http\Controllers\Admin\SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('/assinaturas/{tenant}', [\App\Http\Controllers\Admin\SubscriptionController::class, 'show'])->whereUuid('tenant')->name('subscriptions.show');
    Route::put('/assinaturas/{tenant}', [\App\Http\Controllers\Admin\SubscriptionController::class, 'update'])->whereUuid('tenant')->name('subscriptions.update');
    Route::post('/assinaturas/{tenant}/creditos', [\App\Http\Controllers\Admin\SubscriptionController::class, 'credits'])->whereUuid('tenant')->middleware('throttle:30,1,admin-credits')->name('subscriptions.credits');
    Route::get('/saude', [\App\Http\Controllers\Admin\HealthController::class, 'index'])->name('health');
    Route::get('/selagem', [\App\Http\Controllers\Admin\SealingController::class, 'index'])->name('sealing');
    Route::post('/selagem/teste', [\App\Http\Controllers\Admin\SealingController::class, 'test'])->middleware('throttle:10,1,admin-sealing-test')->name('sealing.test');

    // só super admin
    Route::middleware('super.admin')->group(function () {
        Route::get('/planos', [\App\Http\Controllers\Admin\PlanController::class, 'index'])->name('plans.index');
        Route::get('/planos/novo', [\App\Http\Controllers\Admin\PlanController::class, 'create'])->name('plans.create');
        Route::post('/planos', [\App\Http\Controllers\Admin\PlanController::class, 'store'])->name('plans.store');
        Route::get('/planos/{plan}/editar', [\App\Http\Controllers\Admin\PlanController::class, 'edit'])->whereUuid('plan')->name('plans.edit');
        Route::put('/planos/{plan}', [\App\Http\Controllers\Admin\PlanController::class, 'update'])->whereUuid('plan')->name('plans.update');
        Route::post('/planos/{plan}/padrao', [\App\Http\Controllers\Admin\PlanController::class, 'makeDefault'])->whereUuid('plan')->name('plans.default');
        Route::delete('/planos/{plan}', [\App\Http\Controllers\Admin\PlanController::class, 'destroy'])->whereUuid('plan')->name('plans.destroy');
        Route::get('/usuarios', [\App\Http\Controllers\Admin\PlatformUserController::class, 'index'])->name('users.index');
        Route::post('/usuarios/promover', [\App\Http\Controllers\Admin\PlatformUserController::class, 'promote'])->middleware('throttle:20,1,platform-users')->name('users.promote');
        Route::post('/usuarios/convidar', [\App\Http\Controllers\Admin\PlatformUserController::class, 'invite'])->middleware('throttle:20,1,platform-users')->name('users.invite');
        Route::put('/usuarios/{user}', [\App\Http\Controllers\Admin\PlatformUserController::class, 'role'])->name('users.role');
        Route::delete('/usuarios/{user}', [\App\Http\Controllers\Admin\PlatformUserController::class, 'revoke'])->name('users.revoke');
        Route::get('/email', [\App\Http\Controllers\Admin\MailController::class, 'edit'])->name('mail');
        Route::put('/email', [\App\Http\Controllers\Admin\MailController::class, 'update'])->name('mail.update');
        Route::post('/email/teste', [\App\Http\Controllers\Admin\MailController::class, 'test'])->middleware('throttle:10,1,admin-mail-test')->name('mail.test');
        Route::delete('/email', [\App\Http\Controllers\Admin\MailController::class, 'reset'])->name('mail.reset');
        Route::get('/configuracoes', [\App\Http\Controllers\Admin\SettingsController::class, 'edit'])->name('settings');
        Route::put('/configuracoes', [\App\Http\Controllers\Admin\SettingsController::class, 'update'])->name('settings.update');
    });
});

Route::middleware(['auth', 'tenant.active'])->group(function () {
    Route::get('/painel', [\App\Http\Controllers\Web\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/envelopes', [EnvelopeController::class, 'index'])->name('envelopes.index');

    Route::get('/envelopes/novo', [EnvelopeController::class, 'create'])->name('envelopes.create');
    Route::post('/envelopes', [EnvelopeController::class, 'store'])->name('envelopes.store');
    Route::get('/envelopes/{envelope}', [EnvelopeController::class, 'show'])->name('envelopes.show');
    Route::post('/envelopes/{envelope}/enviar', [EnvelopeController::class, 'send'])->name('envelopes.send');
    Route::post('/envelopes/{envelope}/signatarios/{signer}/reenviar', [EnvelopeController::class, 'resend'])->name('envelopes.resend');
    Route::post('/envelopes/{envelope}/signatarios/{signer}/novo-link', [EnvelopeController::class, 'regenerate'])->name('envelopes.regenerate');
    Route::post('/envelopes/{envelope}/selar', [EnvelopeController::class, 'reseal'])->middleware('throttle:10,1,reseal')->name('envelopes.reseal');
    Route::post('/envelopes/{envelope}/cancelar', [EnvelopeController::class, 'cancel'])->name('envelopes.cancel');
    Route::get('/envelopes/{envelope}/original', [EnvelopeController::class, 'original'])->name('envelopes.original');
    Route::get('/envelopes/{envelope}/anexos/{attachment}', [EnvelopeController::class, 'attachment'])->name('envelopes.attachment');
    Route::get('/envelopes/{envelope}/selado', [EnvelopeController::class, 'final'])->name('envelopes.final');

    Route::post('/envelopes/{envelope}/lixeira', [EnvelopeController::class, 'trash'])->name('envelopes.trash');
    Route::post('/envelopes/{envelope}/restaurar', [EnvelopeController::class, 'restore'])->name('envelopes.restore');
    Route::post('/envelopes/{envelope}/arquivar', [EnvelopeController::class, 'archive'])->name('envelopes.archive');
    Route::post('/envelopes/{envelope}/desarquivar', [EnvelopeController::class, 'unarchive'])->name('envelopes.unarchive');
    Route::get('/documentos/minhas-assinaturas', [\App\Http\Controllers\Web\MySignaturesController::class, 'index'])->name('documents.mine');
    Route::get('/documentos/pastas', [EnvelopeController::class, 'foldersIndex'])->name('documents.folders');
    Route::get('/documentos/lixeira', [EnvelopeController::class, 'trashIndex'])->name('documents.trash');

    Route::post('/envelopes/{envelope}/mover', [EnvelopeController::class, 'moveToFolder'])->name('envelopes.move');
    Route::post('/envelopes/{envelope}/etiquetas', [EnvelopeController::class, 'retag'])->name('envelopes.retag');
    Route::post('/envelopes/{envelope}/organizar', [EnvelopeController::class, 'organize'])->name('envelopes.organize');

    // A tela de pastas e etiquetas vive em Documentos > Pastas e etiquetas; o endereço antigo continua valendo.
    Route::redirect('/organizacao', '/documentos/pastas', 301)->name('organization.index');
    Route::post('/organizacao/pastas', [\App\Http\Controllers\Web\OrganizationController::class, 'storeFolder'])->name('folders.store');
    Route::put('/organizacao/pastas/{folder}', [\App\Http\Controllers\Web\OrganizationController::class, 'updateFolder'])->name('folders.update');
    Route::post('/organizacao/pastas/{folder}/mover', [\App\Http\Controllers\Web\OrganizationController::class, 'moveFolder'])->name('folders.move');
    Route::get('/organizacao/pastas/{folder}/impacto', [\App\Http\Controllers\Web\OrganizationController::class, 'impactFolder'])->name('folders.impact');
    Route::delete('/organizacao/pastas/{folder}', [\App\Http\Controllers\Web\OrganizationController::class, 'destroyFolder'])->name('folders.destroy');
    Route::post('/organizacao/etiquetas', [\App\Http\Controllers\Web\OrganizationController::class, 'storeTag'])->name('tags.store');
    Route::put('/organizacao/etiquetas/{tag}', [\App\Http\Controllers\Web\OrganizationController::class, 'updateTag'])->name('tags.update');
    Route::delete('/organizacao/etiquetas/{tag}', [\App\Http\Controllers\Web\OrganizationController::class, 'destroyTag'])->name('tags.destroy');

    Route::get('/contatos', [\App\Http\Controllers\Web\ContactController::class, 'index'])->name('contacts.index');
    Route::get('/contatos/sugestoes', [\App\Http\Controllers\Web\ContactController::class, 'suggest'])->middleware('throttle:120,1,contacts-suggest')->name('contacts.suggest');
    Route::post('/contatos/importar', [\App\Http\Controllers\Web\ContactController::class, 'import'])->middleware('throttle:10,1,contacts-import')->name('contacts.import');
    Route::get('/contatos/exportar', [\App\Http\Controllers\Web\ContactController::class, 'export'])->name('contacts.export');
    Route::post('/contatos', [\App\Http\Controllers\Web\ContactController::class, 'store'])->middleware('throttle:60,1,contacts')->name('contacts.store');
    Route::put('/contatos/{contact}', [\App\Http\Controllers\Web\ContactController::class, 'update'])->name('contacts.update');
    Route::delete('/contatos/{contact}', [\App\Http\Controllers\Web\ContactController::class, 'destroy'])->name('contacts.destroy');

    Route::get('/configuracoes/usuarios', [\App\Http\Controllers\Web\TeamController::class, 'index'])->name('team.index');
    Route::post('/configuracoes/usuarios', [\App\Http\Controllers\Web\TeamController::class, 'invite'])->middleware('throttle:20,1,team-invite')->name('team.invite');
    Route::post('/configuracoes/usuarios/{user}/reenviar', [\App\Http\Controllers\Web\TeamController::class, 'resend'])->middleware('throttle:20,1,team-invite')->name('team.resend');
    Route::post('/configuracoes/usuarios/{user}/desativar', [\App\Http\Controllers\Web\TeamController::class, 'deactivate'])->name('team.deactivate');
    Route::post('/configuracoes/usuarios/{user}/reativar', [\App\Http\Controllers\Web\TeamController::class, 'reactivate'])->name('team.reactivate');
    Route::delete('/configuracoes/usuarios/{user}', [\App\Http\Controllers\Web\TeamController::class, 'destroy'])->name('team.destroy');

    Route::get('/configuracoes/plano', [\App\Http\Controllers\Web\PlanUsageController::class, 'index'])->name('plan-usage.index');
    Route::get('/configuracoes/marca', [\App\Http\Controllers\Web\BrandController::class, 'edit'])->name('brand.edit');
    Route::put('/configuracoes/marca', [\App\Http\Controllers\Web\BrandController::class, 'update'])->middleware('throttle:20,1,brand')->name('brand.update');
    Route::delete('/configuracoes/marca', [\App\Http\Controllers\Web\BrandController::class, 'reset'])->name('brand.reset');

    Route::get('/calendario', [\App\Http\Controllers\Web\CalendarController::class, 'index'])->name('calendar.index');
    Route::get('/relatorios', [\App\Http\Controllers\Web\ReportController::class, 'index'])->middleware('feature:reports')->name('reports.index');
    Route::get('/relatorios/exportar', [\App\Http\Controllers\Web\ReportController::class, 'export'])->middleware('throttle:20,1,reports-export')->middleware('feature:reports')->name('reports.export');

    Route::get('/modelos', [\App\Http\Controllers\Web\TemplateController::class, 'index'])->middleware('feature:templates')->name('templates.index');
    Route::get('/modelos/novo', [\App\Http\Controllers\Web\TemplateController::class, 'create'])->middleware('feature:templates')->name('templates.create');
    Route::post('/modelos', [\App\Http\Controllers\Web\TemplateController::class, 'store'])->middleware('feature:templates')->name('templates.store');
    Route::get('/modelos/{template}', [\App\Http\Controllers\Web\TemplateController::class, 'edit'])->middleware('feature:templates')->name('templates.edit');
    Route::put('/modelos/{template}', [\App\Http\Controllers\Web\TemplateController::class, 'update'])->middleware('feature:templates')->name('templates.update');
    Route::delete('/modelos/{template}', [\App\Http\Controllers\Web\TemplateController::class, 'destroy'])->middleware('feature:templates')->name('templates.destroy');
    Route::get('/modelos/{template}/previa', [\App\Http\Controllers\Web\TemplateController::class, 'preview'])->middleware('throttle:20,1,template-preview')->middleware('feature:templates')->name('templates.preview');
    Route::get('/modelos/{template}/usar', [\App\Http\Controllers\Web\TemplateController::class, 'use'])->middleware('feature:templates')->name('templates.use');
    Route::post('/modelos/{template}/usar', [\App\Http\Controllers\Web\TemplateController::class, 'generate'])->middleware('feature:templates')->name('templates.generate');

    Route::get('/webhooks', [\App\Http\Controllers\Web\WebhookController::class, 'index'])->middleware('owner')->middleware('feature:webhooks')->name('webhooks.index');
    Route::post('/webhooks', [\App\Http\Controllers\Web\WebhookController::class, 'store'])->middleware('throttle:20,1,webhook-create')->middleware('owner')->middleware('feature:webhooks')->name('webhooks.store');
    Route::post('/webhooks/{endpoint}/alternar', [\App\Http\Controllers\Web\WebhookController::class, 'toggle'])->middleware('owner')->middleware('feature:webhooks')->name('webhooks.toggle');
    Route::post('/webhooks/{endpoint}/testar', [\App\Http\Controllers\Web\WebhookController::class, 'test'])->middleware('throttle:10,1,webhook-test')->middleware('owner')->middleware('feature:webhooks')->name('webhooks.test');
    Route::delete('/webhooks/{endpoint}', [\App\Http\Controllers\Web\WebhookController::class, 'destroy'])->middleware('owner')->middleware('feature:webhooks')->name('webhooks.destroy');
    Route::post('/webhooks/entregas/{delivery}/reenviar', [\App\Http\Controllers\Web\WebhookController::class, 'redeliver'])->middleware('owner')->middleware('feature:webhooks')->name('webhooks.redeliver');

    Route::get('/perfil', [\App\Http\Controllers\Web\ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/perfil', [\App\Http\Controllers\Web\ProfileController::class, 'update'])->middleware('throttle:20,1,profile')->name('profile.update');
    Route::put('/perfil/assinatura', [\App\Http\Controllers\Web\ProfileController::class, 'updateSignature'])->middleware('throttle:20,1,profile-signature')->name('profile.signature.update');
    Route::delete('/perfil/assinatura', [\App\Http\Controllers\Web\ProfileController::class, 'destroySignature'])->name('profile.signature.destroy');
    Route::delete('/perfil/foto', [\App\Http\Controllers\Web\ProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
    Route::get('/perfil/foto/{user}', [\App\Http\Controllers\Web\ProfileController::class, 'photo'])->name('profile.photo');

    Route::get('/configuracoes/assinatura-da-empresa', [\App\Http\Controllers\Web\CompanySigningController::class, 'edit'])->name('company.signing.edit');
    Route::put('/configuracoes/assinatura-da-empresa', [\App\Http\Controllers\Web\CompanySigningController::class, 'update'])->middleware('throttle:20,1,company-signing')->name('company.signing.update');

    Route::get('/api-keys', [ApiKeyController::class, 'index'])->middleware('owner')->name('api-keys.index');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->middleware('owner')->name('api-keys.store');
    Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->middleware('owner')->name('api-keys.destroy');
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

// Referência pública da API
Route::get('/api/docs', [\App\Http\Controllers\Api\V1\DocsController::class, 'page'])->name('api.docs');

// Verificação pública
Route::get('/verificar', [VerificationController::class, 'index'])->name('verify.index');
Route::post('/verificar', [VerificationController::class, 'lookup'])->middleware('throttle:20,1,verify-lookup')->name('verify.lookup');
Route::get('/verificar/{code}', [VerificationController::class, 'show'])->middleware('throttle:60,1,verify-show')->name('verify.show');
Route::post('/verificar/{code}', [VerificationController::class, 'check'])->middleware('throttle:20,1,verify-check')->name('verify.check');
