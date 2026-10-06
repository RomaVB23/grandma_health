<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Services\Telegram\BotStore;

class WatchStatus
{
    public function snapshot(): array
    {
        $now = BotStore::now();
        $device = config('telemetry.device_id');
        $query = fn () => DB::table('watch_events')->where('device_id', $device);
        $pulse = $query()->whereNotNull('bpm')->orderByDesc('measured_at_ms')->orderByDesc('id')->first();
        $snapshot = $query()->orderByDesc('watch_sent_at_ms')->orderByDesc('id')->first();
        $heartbeat = $query()->where('source', 'heartbeat')->orderByDesc('received_at_ms')->orderByDesc('id')->first();
        $contact = $query()->where('live_contact', true)->orderByDesc('received_at_ms')->orderByDesc('id')->first();
        $wearing = $query()->where('live_contact', true)->orderByDesc('watch_sent_at_ms')->orderByDesc('id')->first();
        $upload = $query()->orderByDesc('id')->first();
        $pulseAge = $pulse ? max(0, $now - $pulse->measured_at_ms) : null;
        $contactAge = $contact ? max(0, $now - $contact->received_at_ms) : null;

        $s = [
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
            'wearing_state' => $contactAge !== null && $contactAge < config('telemetry.contact_timeout_ms')
                ? ($wearing?->wearing_state ?? 'unknown') : 'unknown',
            'last_known_wearing_state' => $wearing?->wearing_state ?? 'unknown',
            'wearing_since_ms' => $wearing?->wearing_since_ms,
            'wearing_reported_at_ms' => $wearing?->watch_sent_at_ms,
        ];
        $rules = app(MonitoringSettings::class)->get();
        $eligible = $pulse !== null && MonitoringEligibility::pulse($pulse, $s, $rules, $now);
        return $s + ['pulse_control_enabled' => (bool) $rules->pulse_enabled, 'pulse_lower' => $rules->pulse_lower,
            'pulse_upper' => $rules->pulse_upper, 'pulse_eligible' => $eligible,
            'pulse_control_status' => !$rules->pulse_enabled ? 'disabled' : (!$eligible ? 'no_current_measurement'
                : (MonitoringEligibility::outside($pulse->bpm, $rules) ? 'out_of_range' : 'in_range'))];
    }
}
