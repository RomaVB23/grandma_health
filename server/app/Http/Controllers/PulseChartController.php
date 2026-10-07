<?php

namespace App\Http\Controllers;

use App\Services\MonitoringSettings;
use App\Services\WatchHistory;
use App\Services\WatchPeriodReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PulseChartController
{
    public function __invoke(Request $request, WatchHistory $history, MonitoringSettings $settings, WatchPeriodReport $report): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', Rule::in(['1h', '6h', '24h', 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d\TH:i'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d\TH:i'],
            'gap_minutes' => ['sometimes', 'integer', 'between:3,60'],
        ], [
            'period.in' => 'Выберите период графика.',
            'from.*' => 'Укажите дату и время начала.', 'to.*' => 'Укажите дату и время окончания.',
            'gap_minutes.*' => 'Разрыв графика можно задать от 3 до 60 минут.',
        ]);
        $timezone = config('dashboard.timezone');
        $now = CarbonImmutable::now($timezone);
        $period = $data['period'] ?? '24h';
        $end = $now->getTimestampMs();
        if ($period === 'custom') {
            $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['from'], $timezone)->getTimestampMs();
            $requestedEnd = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['to'], $timezone)->getTimestampMs();
            if ($requestedEnd <= $start || $requestedEnd - $start > 31 * 86_400_000) {
                throw ValidationException::withMessages(['to' => 'Окончание должно быть позже начала; максимальный период — 31 день.']);
            }
            $end = min($end, $requestedEnd);
            if ($start >= $end) {
                throw ValidationException::withMessages(['from' => 'Начало периода должно быть в прошлом.']);
            }
        } else {
            $hours = ['1h' => 1, '6h' => 6, '24h' => 24][$period];
            $start = $now->subHours($hours)->getTimestampMs();
        }
        // Same de-duplication as the table; a heartbeat can be the only stored
        // copy of a real measurement. Upload time never becomes measurement time.
        $query = $history->query(['mode' => 'measurements', 'source' => 'all', 'from' => null, 'to' => null], $timezone)
            ->whereBetween('measured_at_ms', [$start, $end])->orderBy('measured_at_ms')->orderBy('id');
        $rows = $query->limit(20_001)->get(['measured_at_ms', 'bpm']);
        if ($rows->count() > 20_000) {
            throw ValidationException::withMessages(['period' => 'Слишком много замеров для одного графика. Выберите более короткий период.']);
        }
        $rules = $settings->get();
        $points = $rows->map(fn ($row) => [(int) $row->measured_at_ms, (int) $row->bpm])->all();
        $gap = (int) ($data['gap_minutes'] ?? 10) * 60_000;
        return response()->json([
            'timezone' => $timezone, 'from_ms' => $start, 'to_ms' => $end,
            'gap_ms' => $gap,
            'thresholds' => ['lower' => (int) $rules->pulse_lower, 'upper' => (int) $rules->pulse_upper,
                'enabled' => (bool) $rules->pulse_enabled],
            'points' => $points,
            'report' => $report->build($points, $start, $end, $gap),
        ]);
    }
}
