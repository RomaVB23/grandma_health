<?php

use App\Http\Controllers\WatchEventController;
use App\Http\Controllers\WatchStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('telemetry.token')->group(function (): void {
    Route::post('/events', [WatchEventController::class, 'store']);
    Route::get('/history', [WatchEventController::class, 'history']);
    Route::get('/status', WatchStatusController::class);
});
