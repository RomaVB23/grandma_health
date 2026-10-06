<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWatchEventRequest;
use App\Services\TelemetryEpoch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WatchEventController
{
    public function store(StoreWatchEventRequest $request, TelemetryEpoch $epochs): JsonResponse
    {
        $payload = $request->payload();
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($payload, $hash, $epochs): JsonResponse {
            $epoch = $epochs->lock($payload['device_id']);
            $now = (int) floor(microtime(true) * 1000);
            if ($payload['watch_sent_at_ms'] <= $epoch->cleared_before_ms) {
                // A positive ACK releases the phone's durable queue without putting
                // pre-reset samples back in history. Existing Android ACK parsing
                // requires event_id and server_received_at_ms, not a database row ID.
                return response()->json([
                    'id' => null, 'event_id' => $payload['event_id'], 'duplicate' => false,
                    'server_received_at_ms' => $now, 'live_contact' => false,
                    'ignored_before_reset' => true,
                ]);
            }
            $storedPayload = $payload;
            if ($payload['measured_at_ms'] !== null && $payload['measured_at_ms'] <= $epoch->cleared_before_ms) {
                $storedPayload['bpm'] = null;
                $storedPayload['measured_at_ms'] = null;
            }
            $tolerance = (int) config('telemetry.clock_tolerance_ms');

            // Offline/replayed samples enter history, but do not become live contact.
            $liveContact = $payload['source'] === 'heartbeat'
                && abs($now - $payload['received_at_ms']) <= $tolerance
                && abs($payload['received_at_ms'] - $payload['watch_sent_at_ms']) <= $tolerance;

            // ON CONFLICT handles concurrent retries without swallowing other SQL errors.
            $inserted = DB::affectingStatement(
                'INSERT INTO watch_events (event_id, device_id, source, received_at_ms, watch_sent_at_ms, '
                .'bpm, measured_at_ms, battery_percent, charging, monitoring_status, server_received_at_ms, '
                .'live_contact, payload_hash, wearing_state, wearing_since_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
                .'ON CONFLICT(event_id) DO NOTHING',
                [
                    ...array_map(fn ($key) => $storedPayload[$key], ['event_id', 'device_id', 'source', 'received_at_ms',
                        'watch_sent_at_ms', 'bpm', 'measured_at_ms', 'battery_percent', 'charging', 'monitoring_status']),
                    $now, (int) $liveContact, $hash, $storedPayload['wearing_state'] ?? 'unknown',
                    $storedPayload['wearing_since_ms'] ?? null,
                ],
            );

            $event = DB::table('watch_events')->where('event_id', $payload['event_id'])->first();
            if ($event->payload_hash !== $hash) {
                return response()->json(['error' => 'event_id already belongs to a different payload'], 409);
            }

            return response()->json([
                'id' => $event->id,
                'event_id' => $event->event_id,
                'duplicate' => $inserted === 0,
                'server_received_at_ms' => $event->server_received_at_ms,
                'live_contact' => (bool) $event->live_contact,
            ], $inserted === 0 ? 200 : 201);
        }, 5);
    }

    public function history(Request $request): JsonResponse
    {
        $query = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,500'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $events = DB::table('watch_events')
            ->where('device_id', config('telemetry.device_id'))
            ->when(isset($query['before_id']), fn ($builder) => $builder->where('id', '<', $query['before_id']))
            ->orderByDesc('id')
            ->limit($query['limit'] ?? 50)
            ->get()
            ->map(function (object $event): array {
                $row = (array) $event;
                unset($row['payload_hash']);
                $row['charging'] = (bool) $row['charging'];
                $row['live_contact'] = (bool) $row['live_contact'];

                return $row;
            });

        return response()->json(['events' => $events, 'next_before_id' => $events->last()['id'] ?? null]);
    }
}
