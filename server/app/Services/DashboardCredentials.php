<?php

namespace App\Services;

use Illuminate\Http\Request;

class DashboardCredentials
{
    public function hash(): ?string
    {
        $path = config('dashboard.password_file');
        $hash = is_string($path) && is_file($path) ? trim((string) @file_get_contents($path)) : '';
        return preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/D', $hash) === 1 ? $hash : null;
    }

    public function authenticated(Request $request, string $hash): bool
    {
        $lastSeen = $request->session()->get('dashboard_last_seen', 0);
        $fingerprint = $request->session()->get('dashboard_fingerprint', '');
        $now = now()->timestamp;
        return is_int($lastSeen) && $lastSeen > 0 && $lastSeen <= $now
            && $now - $lastSeen < config('dashboard.idle_timeout_seconds')
            && is_string($fingerprint) && hash_equals(hash('sha256', $hash), $fingerprint);
    }
}
