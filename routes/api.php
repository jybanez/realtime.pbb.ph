<?php

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
