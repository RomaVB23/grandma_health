<?php

namespace App\Http\Middleware;

use App\Services\DashboardCredentials;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardAccess
{
    public function __construct(private DashboardCredentials $credentials) {}

    public function handle(Request $request, Closure $next, string $mode = 'auth'): Response
    {
        $hash = $this->credentials->hash();
        if ($hash === null) {
            $response = response()->view('dashboard.login', ['configured' => false], 503);
        } elseif ($mode === 'auth' && !$this->credentials->authenticated($request, $hash)) {
            $request->session()->forget(['dashboard_fingerprint', 'dashboard_last_seen']);
            $response = redirect()->route('dashboard.login');
        } else {
            if ($this->credentials->authenticated($request, $hash)) {
                $request->session()->put('dashboard_last_seen', now()->timestamp);
            }
            $response = $next($request);
        }
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self' data:; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
        return $response;
    }
}
