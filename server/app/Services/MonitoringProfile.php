<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Calendar profiles describe a configured schedule, not detected sleep. */
class MonitoringProfile
{
    public function resolve(object $rules, int $now): array
    {
        $local = CarbonImmutable::createFromTimestampMs($now)->setTimezone($rules->profile_timezone);
        $boundaries = [];
        foreach ([-2, -1, 0, 1, 2] as $offset) {
            $date = $local->startOfDay()->addDays($offset);
            foreach (['night' => $rules->night_start, 'day' => $rules->night_end] as $profile => $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $boundaries[$date->setTime($hour, $minute)->getTimestampMs()] = $profile;
            }
        }
        ksort($boundaries, SORT_NUMERIC);
        $start = 0; $scheduled = 'day'; $next = null;
        foreach ($boundaries as $at => $profile) {
            if ($at <= $now) { $start = $at; $scheduled = $profile; }
            else { $next = $at; break; }
        }
        $manual = in_array($rules->profile_mode, ['day', 'night'], true)
            && $rules->profile_override_until_ms !== null && $rules->profile_override_until_ms > $now;
        $profile = $manual ? $rules->profile_mode : $scheduled;
        $from = $manual ? (int) $rules->changed_at_ms : max($start,
            $rules->profile_override_until_ms !== null && $rules->profile_override_until_ms <= $now
                ? (int) $rules->profile_override_until_ms : 0);
        $from = max($from, (int) $rules->changed_at_ms);
        return ['profile' => $profile, 'mode' => $manual ? $profile : 'auto', 'from_ms' => $from,
            'until_ms' => $manual ? min($next, $rules->profile_override_until_ms) : $next,
            'override_until_ms' => $manual ? (int) $rules->profile_override_until_ms : null,
            'key' => ($manual ? 'manual:' : 'auto:').$profile.':'.$from];
    }

    public static function label(string $profile): string
    {
        return $profile === 'night' ? 'Ночной' : 'Дневной';
    }
}
