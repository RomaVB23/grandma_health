<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Descriptive history statistics, never evidence of continuous observation. */
class WatchPeriodReport
{
    public function build(array $points, int $start, int $end, int $gap): array
    {
        $count = count($points);
        $min = $max = null;
        $total = $sumIntervals = $longGaps = 0;
        $maxInterval = $maxFrom = $maxTo = null;
        foreach ($points as $index => [$at, $bpm]) {
            $min = $min === null ? $bpm : min($min, $bpm);
            $max = $max === null ? $bpm : max($max, $bpm);
            $total += $bpm;
            if ($index === 0) continue;
            $previous = $points[$index - 1][0];
            $interval = $at - $previous;
            $sumIntervals += $interval;
            if ($maxInterval === null || $interval > $maxInterval) {
                $maxInterval = $interval; $maxFrom = $previous; $maxTo = $at;
            }
            if ($interval > $gap) $longGaps++;
        }

        // Charge snapshots have their own watch timestamp, independent of pulse
        // measurement and server upload times. Retransmissions count only once.
        $snapshots = DB::table('watch_events')->where('device_id', config('telemetry.device_id'))
            ->whereBetween('watch_sent_at_ms', [$start, $end])->whereBetween('battery_percent', [0, 100]);
        $ids = (clone $snapshots)->selectRaw('MIN(id)')->groupBy('watch_sent_at_ms');
        $unique = (clone $snapshots)->whereIn('id', $ids);
        $stats = (clone $unique)->selectRaw('COUNT(*) AS count, MAX(charging) AS charging_observed')->first();
        $first = (clone $unique)->orderBy('watch_sent_at_ms')->orderBy('id')->first(['watch_sent_at_ms', 'battery_percent']);
        $last = (clone $unique)->orderByDesc('watch_sent_at_ms')->orderByDesc('id')->first(['watch_sent_at_ms', 'battery_percent']);
        $snapshot = fn ($row) => $row ? ['at_ms' => (int) $row->watch_sent_at_ms, 'percent' => (int) $row->battery_percent] : null;

        return [
            'pulse' => ['count' => $count, 'min_bpm' => $min, 'max_bpm' => $max,
                // Arithmetic mean of samples; irregular intervals are not weighted.
                'mean_bpm' => $count ? round($total / $count, 1) : null,
                'first_at_ms' => $count ? $points[0][0] : null,
                'last_at_ms' => $count ? $points[$count - 1][0] : null],
            'intervals' => ['mean_ms' => $count > 1 ? (int) round($sumIntervals / ($count - 1)) : null,
                'max_ms' => $maxInterval, 'max_from_ms' => $maxFrom, 'max_to_ms' => $maxTo,
                'long_gap_count' => $longGaps],
            'edges' => ['before_first_ms' => $count ? $points[0][0] - $start : $end - $start,
                'after_last_ms' => $count ? $end - $points[$count - 1][0] : null],
            'battery' => ['snapshot_count' => (int) $stats->count, 'first' => $snapshot($first), 'last' => $snapshot($last),
                'delta_pp' => $stats->count > 1 ? (int) $last->battery_percent - (int) $first->battery_percent : null,
                'charging_observed' => $stats->count ? (bool) $stats->charging_observed : null],
        ];
    }
}
