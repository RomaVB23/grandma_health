<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** One measurement selection for the web chart and Telegram images. */
class PulseChartData
{
    public function __construct(private WatchHistory $history, private WatchPeriodReport $report, private ChargingHistory $charging) {}

    public function build(int $start, int $end, string $timezone, int $gap = 600_000, bool $endExclusive = false): array
    {
        if ($start >= $end || $end - $start > 31 * 86_400_000) {
            throw new \InvalidArgumentException('Invalid chart period');
        }
        new \DateTimeZone($timezone);
        // Heartbeats may contain the only stored copy of a real measurement.
        // WatchHistory removes repeated copies; upload time never replaces measurement time.
        $query = $this->history->query(['mode' => 'measurements', 'source' => 'all', 'from' => null, 'to' => null], $timezone)
            ->where('measured_at_ms', '>=', $start)->where('measured_at_ms', $endExclusive ? '<' : '<=', $end)
            ->orderBy('measured_at_ms')->orderBy('id');
        $rows = $query->limit(20_001)->get(['measured_at_ms', 'bpm']);
        if ($rows->count() > 20_000) {
            throw ValidationException::withMessages(['period' => 'Слишком много замеров для одного графика. Выберите более короткий период.']);
        }
        $points = $rows->map(fn ($row) => [(int) $row->measured_at_ms, (int) $row->bpm])->all();
        return ['timezone' => $timezone, 'from_ms' => $start, 'to_ms' => $end, 'gap_ms' => $gap,
            'points' => $points, 'charging' => $this->charging->build($start, $end),
            'report' => $this->report->build($points, $start, $end, $gap)];
    }
}
