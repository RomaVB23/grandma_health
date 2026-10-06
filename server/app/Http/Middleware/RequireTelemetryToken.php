<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTelemetryToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('telemetry.token');
        if (strlen($expected) < 32) {
            return response()->json(['error' => 'Server token is not configured'], 503);
        }

        $provided = $request->bearerToken();
        if (!is_string($provided) || !hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
