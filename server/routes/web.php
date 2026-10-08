<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardClearController;
use App\Http\Controllers\DashboardLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('dashboard:guest')->group(function (): void {
    Route::get('/login', [DashboardLoginController::class, 'show'])->name('dashboard.login');
    Route::post('/login', [DashboardLoginController::class, 'login'])->name('dashboard.authenticate');
});

Route::middleware('dashboard')->group(function (): void {
    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', DashboardController::class)->name('dashboard.index');
    Route::post('/dashboard/measurement-requests', [\App\Http\Controllers\MeasurementController::class, 'create'])->name('dashboard.measurement.create');
    Route::get('/dashboard/measurement-requests/{id}', [\App\Http\Controllers\MeasurementController::class, 'status'])->whereUuid('id')->name('dashboard.measurement.status');
    Route::get('/dashboard/pulse-chart', \App\Http\Controllers\PulseChartController::class)->name('dashboard.pulse-chart');
    Route::get('/dashboard/monitoring', [\App\Http\Controllers\MonitoringSettingsController::class, 'show'])->name('dashboard.monitoring');
    Route::post('/dashboard/monitoring', [\App\Http\Controllers\MonitoringSettingsController::class, 'save'])->name('dashboard.monitoring.save');
    Route::post('/dashboard/monitoring/mode', [\App\Http\Controllers\MonitoringSettingsController::class, 'mode'])->name('dashboard.monitoring.mode');
    Route::get('/dashboard/clear', [DashboardClearController::class, 'show'])->name('dashboard.clear');
    Route::post('/dashboard/clear', [DashboardClearController::class, 'clear'])->name('dashboard.clear.confirm');
    Route::post('/logout', [DashboardLoginController::class, 'logout'])->name('dashboard.logout');
});
