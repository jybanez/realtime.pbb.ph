<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BrowserDataController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\CurrentUserController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\PolicyController;
use App\Http\Controllers\Admin\RuntimeSettingsController;
use App\Http\Controllers\Admin\SandboxController;
use App\Http\Controllers\Admin\SessionStateController;
use App\Http\Controllers\Admin\SessionController as AdminSessionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AccountAdminController;
use App\Http\Controllers\AccountSsoController;
use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\PublicSdkDocsController;
use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StatusController::class, 'landing'])->name('status');
Route::get('/api/docs', [ApiDocsController::class, 'index'])->name('api.docs');
Route::get('/api/sdk-docs/{doc}', [BrowserDataController::class, 'sdkDoc'])->name('sdk.docs.public.show');
Route::get('/sdk-docs', [PublicSdkDocsController::class, 'index'])->name('sdk.public.index');
Route::get('/sdk-docs/index.json', [PublicSdkDocsController::class, 'indexJson'])->name('sdk.public.index-json');
Route::get('/sdk-docs/sitemap.xml', [PublicSdkDocsController::class, 'sitemap'])->name('sdk.public.sitemap');
Route::get('/sdk-docs/backend', [PublicSdkDocsController::class, 'backend'])->name('sdk.public.backend');
Route::get('/sdk-docs/tutorials/{tutorial}', [PublicSdkDocsController::class, 'tutorial'])->name('sdk.public.tutorials.show');
Route::get('/sdk-docs/reference/{doc}', [PublicSdkDocsController::class, 'reference'])->name('sdk.public.reference');

Route::post('/admin/login', [AdminAuthController::class, 'store'])->name('login.store');
Route::post('/admin/logout', [AdminAuthController::class, 'destroy'])->name('logout');
Route::get('/auth/account/redirect', [AccountSsoController::class, 'redirect'])->name('account.redirect');
Route::get('/auth/account/callback', [AccountSsoController::class, 'callback'])->name('account.callback');
Route::get('/auth/logout', [AccountSsoController::class, 'logout'])->name('account.logout');

Route::prefix('api/account-admin')
    ->middleware(['account-admin', 'throttle:120,1'])
    ->name('account-admin.')
    ->group(function (): void {
        Route::get('/meta', [AccountAdminController::class, 'meta'])->name('meta');
        Route::get('/users/{pbbUserId}', [AccountAdminController::class, 'show'])->name('users.show');
        Route::put('/users/{pbbUserId}', [AccountAdminController::class, 'provision'])->name('users.provision');
        Route::patch('/users/{pbbUserId}/role', [AccountAdminController::class, 'updateRole'])->name('users.role');
        Route::patch('/users/{pbbUserId}/status', [AccountAdminController::class, 'updateStatus'])->name('users.status');
    });

Route::prefix('api/admin')->name('admin.api.')->group(function () {
    Route::get('/csrf-token', [SessionStateController::class, 'csrfToken'])->name('csrf');
    Route::get('/bootstrap', [SessionStateController::class, 'bootstrap'])->name('bootstrap');
    Route::post('/login', [AdminAuthController::class, 'store'])->name('login');
});

Route::middleware(['auth', 'operator'])->prefix('api/admin')->name('admin.api.')->group(function () {
    Route::get('/session/ping', [SessionStateController::class, 'ping'])->name('session.ping');
    Route::post('/logout', [AdminAuthController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', [BrowserDataController::class, 'dashboard'])->name('dashboard');
    Route::get('/clients', [BrowserDataController::class, 'clients'])->name('clients');
    Route::get('/client-options', [BrowserDataController::class, 'clientOptions'])->name('clients.options');
    Route::get('/clients/{client}', [BrowserDataController::class, 'client'])->name('clients.show');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::patch('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
    Route::get('/policies', [BrowserDataController::class, 'policies'])->name('policies');
    Route::get('/policies/{policy}', [BrowserDataController::class, 'policy'])->name('policies.show');
    Route::post('/policies', [PolicyController::class, 'store'])->name('policies.store');
    Route::patch('/policies/{policy}', [PolicyController::class, 'update'])->name('policies.update');
    Route::delete('/policies/{policy}', [PolicyController::class, 'destroy'])->name('policies.destroy');
    Route::get('/projects', [BrowserDataController::class, 'projects'])->name('projects');
    Route::get('/projects/{project}', [BrowserDataController::class, 'project'])->name('projects.show');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::get('/sandbox/context', [SandboxController::class, 'context'])->name('sandbox.context');
    Route::post('/sandbox/admission', [SandboxController::class, 'admission'])->name('sandbox.admission');
    Route::get('/sessions', [BrowserDataController::class, 'sessions'])->name('sessions');
    Route::get('/audit', [BrowserDataController::class, 'audit'])->name('audit');
    Route::get('/users', [BrowserDataController::class, 'users'])->name('users');
    Route::get('/user-options', [BrowserDataController::class, 'userOptions'])->name('users.options');
    Route::get('/users/{user}', [BrowserDataController::class, 'user'])->name('users.show');
    Route::get('/users/{user}/audit', [BrowserDataController::class, 'userAudit'])->name('users.audit');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::get('/operations', [BrowserDataController::class, 'operations'])->name('operations');
    Route::patch('/runtime-settings/maestro-telemetry', [RuntimeSettingsController::class, 'updateMaestroTelemetry'])->name('runtime-settings.maestro-telemetry.update');
    Route::get('/telemetry', [BrowserDataController::class, 'telemetry'])->name('telemetry');
    Route::get('/sdk-docs/{doc}', [BrowserDataController::class, 'sdkDoc'])->name('sdk.docs.show');
    Route::get('/sdk-downloads/backend-php', [BrowserDataController::class, 'downloadBackendSdk'])->name('sdk.downloads.backend');
    Route::get('/sdk-downloads/demo-bundle', [BrowserDataController::class, 'downloadSdkDemoBundle'])->name('sdk.downloads.demo-bundle');
    Route::get('/me', [CurrentUserController::class, 'show'])->name('me.show');
    Route::patch('/me', [CurrentUserController::class, 'update'])->name('me.update');
    Route::put('/me', [CurrentUserController::class, 'update']);
    Route::post('/me/password', [CurrentUserController::class, 'password'])->name('me.password');
});

Route::middleware(['auth', 'operator'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('clients', ClientController::class);
    Route::resource('policies', PolicyController::class);
    Route::resource('projects', ProjectController::class)->only(['index', 'show', 'edit']);
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('sandbox', [SandboxController::class, 'index'])->name('sandbox.index');
    Route::get('presence-inspector', [SandboxController::class, 'index'])->name('presence.index');
    Route::get('sdk', [DashboardController::class, 'index'])->name('sdk.index');
    Route::get('sdk/backend', [DashboardController::class, 'index'])->name('sdk.backend');
    Route::get('sdk/tutorials/{tutorial}', [DashboardController::class, 'index'])->name('sdk.tutorials.show');

    Route::get('sessions', [AdminSessionController::class, 'index'])->name('sessions.index');
    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('operations', [OperationsController::class, 'index'])->name('operations.index');
    Route::get('telemetry', [OperationsController::class, 'telemetry'])->name('telemetry.index');
});
