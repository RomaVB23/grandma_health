<?php

namespace App\Http\Controllers;

use App\Services\DashboardCredentials;
use App\Services\DashboardText;
use App\Services\TelemetryEpoch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardClearController
{
    public function show(TelemetryEpoch $epochs, DashboardText $text)
    {
        $device = config('telemetry.device_id');
        return view('dashboard.clear', [
            'device' => $device, 'epoch' => $epochs->current($device), 'text' => $text,
            'count' => DB::table('watch_events')->where('device_id', $device)->count(),
        ]);
    }

    public function clear(Request $request, DashboardCredentials $credentials, TelemetryEpoch $epochs)
    {
        $key = 'dashboard-clear:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Слишком много попыток. Повторите через '.RateLimiter::availableIn($key).' с.']);
        }
        $data = $request->validate([
            'confirmation' => ['required', Rule::in(['ОЧИСТИТЬ'])],
            'generation' => ['required', 'integer', 'min:0'],
            'password' => ['required', 'string'],
        ], ['confirmation.*' => 'Для подтверждения введите слово ОЧИСТИТЬ.',
            'generation.*' => 'Откройте новый экран подтверждения.', 'password.required' => 'Введите пароль веб-интерфейса.']);
        $hash = $credentials->hash();
        if (strlen($data['password']) > 72 || $hash === null || !password_verify($data['password'], $hash)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['password' => 'Неверный пароль веб-интерфейса.']);
        }
        RateLimiter::clear($key);
        $result = $epochs->clear(config('telemetry.device_id'), (int) $data['generation']);
        return redirect()->route('dashboard.index')->with('history_cleared', $result);
    }
}
