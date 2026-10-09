<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Charger observations use watch snapshot time, never the carried pulse time. */
class ChargingHistory
{
    public function build(int $start, int $end): array
    {
        $query = fn () => DB::table('watch_events')->where('device_id', config('telemetry.device_id'));
        $before = $query()->where('watch_sent_at_ms', '<', $start)
            ->orderByDesc('watch_sent_at_ms')->orderByDesc('id')->first(['watch_sent_at_ms', 'charging', 'charging_since_ms']);
        $rows = $query()->whereBetween('watch_sent_at_ms', [$start, $end])
            ->orderBy('watch_sent_at_ms')->orderBy('id')->get(['watch_sent_at_ms', 'charging', 'charging_since_ms'])->all();
        if ($before) array_unshift($rows, $before);
        // Contradictory states at one watch instant are not evidence of charging.
        $observations = [];
        foreach ($rows as $row) {
            $at = (int) $row->watch_sent_at_ms;
            if (isset($observations[$at]) && $observations[$at]->charging !== null
                && (bool) $observations[$at]->charging !== (bool) $row->charging) {
                $observations[$at] = (object) ['watch_sent_at_ms' => $at, 'charging' => null, 'charging_since_ms' => null];
            } elseif (!isset($observations[$at])) {
                $observations[$at] = $row;
            }
        }
        $segments = []; $open = null; $previousOff = null;
        $lease = (int) config('telemetry.contact_timeout_ms');
        $close = function (int $until) use (&$segments, &$open, $start, $end): void {
            if ($open === null) return;
            $a = max($start, $open['start']); $b = min($end, $until);
            if ($a < $b) $segments[] = [$a, $b];
            $open = null;
        };
        foreach ($observations as $row) {
            $at = (int) $row->watch_sent_at_ms;
            $since = $row->charging_since_ms !== null && $row->charging_since_ms > 0 && $row->charging_since_ms <= $at
                ? (int) $row->charging_since_ms : null;
            if ($row->charging === null || !(bool) $row->charging) {
                if ($open !== null) {
                    // A recorded disconnection closes the observed session even if its
                    // DataItem reached the server after an offline interval. Initial
                    // observations after a watch service restart have no transition time.
                    $confirmedEnd = $row->charging !== null && $since !== null && $since >= $open['last'];
                    $close($confirmedEnd ? $since : min($at, $open['last'] + $lease));
                }
                $previousOff = $at;
                continue;
            }
            $sameSession = $open !== null && $since !== null && $since === $open['since'];
            if ($open !== null && (($since !== null && $open['since'] !== null && !$sameSession)
                || ($at - $open['last'] > $lease && !$sameSession))) {
                $close(min($at, $open['last'] + $lease, $since ?? $at));
            }
            if ($open === null) {
                $begin = $since !== null && ($previousOff === null || $since >= $previousOff) ? $since : $at;
                $open = ['start' => $begin, 'last' => $at, 'since' => $since];
            } else $open['last'] = $at;
        }
        if ($open !== null) $close($open['last'] + $lease);
        $merged = [];
        foreach ($segments as [$a, $b]) {
            $i = count($merged) - 1;
            if ($i >= 0 && $a <= $merged[$i][1]) $merged[$i][1] = max($b, $merged[$i][1]);
            else $merged[] = [$a, $b];
        }
        return ['intervals' => $merged, 'total_ms' => array_sum(array_map(fn ($p) => $p[1] - $p[0], $merged)),
            'basis' => 'watch_charging_snapshots',
            'note' => 'Оранжевым показана зарядка по сообщениям часов. Без времени смены состояния границы приблизительные; отсутствие сообщений не доказывает зарядку.'];
    }
}
