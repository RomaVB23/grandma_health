<?php

use App\Http\Middleware\RequireTelemetryToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withCommands([
        App\Console\Commands\TelegramRun::class,
        App\Console\Commands\TelegramIdentify::class,
        App\Console\Commands\TelegramHealth::class,
        App\Console\Commands\DashboardPassword::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'telemetry.token' => RequireTelemetryToken::class,
            'dashboard' => App\Http\Middleware\DashboardAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'up') || $request->expectsJson());
    })->create();
