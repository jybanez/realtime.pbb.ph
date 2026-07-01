<?php

use App\Http\Controllers\AccountAdminController;
use App\Http\Controllers\RealtimeSessionController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\EventPublishController;
use App\Http\Middleware\TraceEventPublishRequest;
use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [StatusController::class, 'health']);
Route::get('/ready', [StatusController::class, 'ready']);
Route::get('/metrics', MetricsController::class);
Route::post('/realtime/session', [RealtimeSessionController::class, 'store']);
Route::post('/v1/events/publish', [EventPublishController::class, 'store'])
    ->middleware(TraceEventPublishRequest::class)
    ->name('api.events.publish');

Route::prefix('account-admin')
    ->middleware(['account-admin', 'throttle:120,1'])
    ->name('account-admin.')
    ->group(function (): void {
        Route::get('/meta', [AccountAdminController::class, 'meta'])->name('meta');
        Route::get('/users/{pbbUserId}', [AccountAdminController::class, 'show'])->name('users.show');
        Route::put('/users/{pbbUserId}', [AccountAdminController::class, 'provision'])->name('users.provision');
        Route::delete('/users/{pbbUserId}', [AccountAdminController::class, 'removeAccess'])->name('users.remove');
        Route::patch('/users/{pbbUserId}/role', [AccountAdminController::class, 'updateRole'])->name('users.role');
        Route::patch('/users/{pbbUserId}/status', [AccountAdminController::class, 'updateStatus'])->name('users.status');
    });
