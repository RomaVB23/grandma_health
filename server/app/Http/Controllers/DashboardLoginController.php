<?php

namespace App\Http\Controllers;

use App\Services\DashboardCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class DashboardLoginController
{
    public function show(Request $request, DashboardCredentials $credentials)
    {
        $hash = $credentials->hash();
        return $hash !== null && $credentials->authenticated($request, $hash)
            ? redirect()->route('dashboard.index') : view('dashboard.login', ['configured' => true]);
    }

    public function login(Request $request, DashboardCredentials $credentials)
    {
        $key = 'dashboard-login:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->view('dashboard.login', ['configured' => true,
                'loginError' => 'Слишком много попыток. Повторите через '.RateLimiter::availableIn($key).' с.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        $password = $request->input('password');
        $hash = $credentials->hash();
        if (!is_string($password) || strlen($password) > 72 || $hash === null || !password_verify($password, $hash)) {
            RateLimiter::hit($key, 60);
            return response()->view('dashboard.login', ['configured' => true, 'loginError' => 'Неверный пароль.'], 422);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate(true);
        $request->session()->put('dashboard_fingerprint', hash('sha256', $hash));
        $request->session()->put('dashboard_last_seen', now()->timestamp);
        return redirect()->route('dashboard.index');
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('dashboard.login');
    }
}
