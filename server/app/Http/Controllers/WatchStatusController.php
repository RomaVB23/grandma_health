<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WatchStatusController
{
    public function __invoke(): JsonResponse
    {
        $now = (int) floor(microtime(true) * 1000);
        $device = config('telemetry.device_id');
        $query = fn () => DB::table('watch_events')->where('device_id', $device);

        $pulse = $query()->whereNotNull('bpm')->orderByDesc('measured_at_ms')->orderByDesc('id')->first();
        $snapshot = $query()->orderByDesc('watch_sent_at_ms')->orderByDesc('id')->first();
        $heartbeat = $query()->where('source', 'heartbeat')->orderByDesc('received_at_ms')->orderByDesc('id')->first();
        $contact = $query()->where('live_contact', true)
            ->orderByDesc('received_at_ms')->orderByDesc('id')->first();
        $upload = $query()->orderByDesc('id')->first();
        // Original phone/watch time prevents queue replay from refreshing old telemetry.
        $pulseAge = $pulse ? max(0, $now - $pulse->measured_at_ms) : null;
        $contactAge = $contact ? max(0, $now - $contact->received_at_ms) : null;

        return response()->json([
            'device_id' => $device,
            'server_time_ms' => $now,
            'last_upload_at_ms' => $upload?->server_received_at_ms,
            'last_reported_heartbeat_at_ms' => $heartbeat?->received_at_ms,
            'last_live_contact_at_ms' => $contact?->received_at_ms,
            'contact_age_ms' => $contactAge,
            'contact_recent' => $contactAge !== null && $contactAge < config('telemetry.contact_timeout_ms'),
            'bpm' => $pulse?->bpm,
            'measured_at_ms' => $pulse?->measured_at_ms,
            'measurement_age_ms' => $pulseAge,
            'measurement_stale' => $pulseAge === null || $pulseAge >= config('telemetry.measurement_stale_ms'),
            'battery_percent' => $snapshot?->battery_percent,
            'charging' => $snapshot ? (bool) $snapshot->charging : null,
            'snapshot_at_ms' => $snapshot?->watch_sent_at_ms,
            'monitoring_status' => $heartbeat?->monitoring_status ?? 'unknown',
        ]);
    }
}
