<?php

namespace App\Services;

class MonitoringEligibility
{
    public static function pulse(object $event, array $s, object $rules, int $now): bool
    {
        $ageLimit = $rules->pulse_max_age_seconds * 1000;
        $tolerance = config('telemetry.clock_tolerance_ms');
        return (bool) $rules->pulse_enabled && $s['contact_recent'] && $s['monitoring_status'] === 'active'
            && ($s['wearing_state'] ?? 'unknown') === 'on' && $event->bpm !== null && $event->measured_at_ms !== null
            && $event->wearing_state === 'on' && $event->measured_at_ms >= ($s['wearing_since_ms'] ?? PHP_INT_MAX)
            && $event->measured_at_ms > $rules->changed_at_ms && $event->measured_at_ms <= $now + $tolerance
            && (!isset($rules->profile_until_ms) || $event->measured_at_ms < $rules->profile_until_ms)
            && $now - $event->measured_at_ms < $ageLimit
            && abs($event->server_received_at_ms - $event->received_at_ms) <= $tolerance
            && abs($event->received_at_ms - $event->watch_sent_at_ms) <= $tolerance;
    }

    public static function outside(int $bpm, object $rules): bool
    {
        return $bpm < $rules->pulse_lower || $bpm > $rules->pulse_upper;
    }
}
