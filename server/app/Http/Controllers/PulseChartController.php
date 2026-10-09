<?php

namespace App\Http\Controllers;

use App\Services\MonitoringSettings;
use App\Services\PulseChartData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PulseChartController
{
    public function __invoke(Request $request, PulseChartData $charts, MonitoringSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', Rule::in(['1h', '6h', '12h', '24h', 'custom'])],
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
            $hours = ['1h' => 1, '6h' => 6, '12h' => 12, '24h' => 24][$period];
            $start = $now->subHours($hours)->getTimestampMs();
        }
        $rules = $settings->effective($now->getTimestampMs());
        $gap = (int) ($data['gap_minutes'] ?? 10) * 60_000;
        return response()->json($charts->build($start, $end, $timezone, $gap) + [
            'thresholds' => ['lower' => (int) $rules->pulse_lower, 'upper' => (int) $rules->pulse_upper,
                'enabled' => (bool) $rules->pulse_enabled],
            'threshold_profile' => $rules->effective_profile, 'threshold_mode' => $rules->effective_mode,
        ]);
    }
}
