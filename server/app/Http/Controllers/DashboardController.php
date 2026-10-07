<?php

namespace App\Http\Controllers;

use App\Services\DashboardText;
use App\Services\WatchHistory;
use App\Services\WatchStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController
{
    public function __invoke(Request $request, WatchHistory $history, WatchStatus $status, DashboardText $text)
    {
        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(['measurements', 'events'])],
            'source' => ['sometimes', Rule::in(['all', 'measurement', 'heartbeat'])],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100])],
            'page' => ['sometimes', 'integer', 'between:1,100000'],
        ], [
            'from.date_format' => 'Проверьте дату начала.', 'to.date_format' => 'Проверьте дату окончания.',
            'mode.in' => 'Неизвестный режим истории.', 'source.in' => 'Неизвестный тип события.',
            'per_page.in' => 'На странице можно показывать 25, 50 или 100 строк.',
            'page.*' => 'Некорректный номер страницы.',
        ]);
        $timezone = config('dashboard.timezone');
        $today = CarbonImmutable::now($timezone)->format('Y-m-d');
        $filters = [
            'mode' => $data['mode'] ?? 'measurements', 'source' => $data['source'] ?? 'all',
            'from' => array_key_exists('from', $data) ? $data['from'] : $today,
            'to' => array_key_exists('to', $data) ? $data['to'] : $today,
            'per_page' => (int) ($data['per_page'] ?? 50),
        ];
        if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to' => 'Дата окончания должна быть не раньше даты начала.']);
        }
        $query = $history->query($filters, $timezone);
        $events = (clone $query)->orderByDesc($filters['mode'] === 'measurements' ? 'measured_at_ms' : 'watch_sent_at_ms')
            ->orderByDesc('id')->paginate($filters['per_page'])->withQueryString();
        // Statistics always use unique measurements, never pulse copies from heartbeats.
        $summaryQuery = $history->query(array_replace($filters, ['mode' => 'measurements']), $timezone);
        $summary = $summaryQuery->selectRaw('COUNT(*) as count, MIN(bpm) as min_bpm, MAX(bpm) as max_bpm, AVG(bpm) as avg_bpm')->first();
        return view('dashboard.index', compact('events', 'filters', 'summary', 'timezone', 'text') + ['status' => $status->snapshot(), 'measurementRequest' => app(\App\Services\MeasurementRequests::class)->latest()]);
    }
}
