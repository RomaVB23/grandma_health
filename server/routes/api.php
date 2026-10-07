<?php

use App\Http\Controllers\WatchEventController;
use App\Http\Controllers\WatchStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('telemetry.token')->group(function (): void {
    Route::post('/measurement-requests/claim', [\App\Http\Controllers\MeasurementController::class, 'claim']);
    Route::post('/measurement-requests/{id}/result', [\App\Http\Controllers\MeasurementController::class, 'result'])->whereUuid('id');
    Route::post('/events', [WatchEventController::class, 'store']);
    Route::get('/history', [WatchEventController::class, 'history']);
    Route::get('/status', WatchStatusController::class);
});
