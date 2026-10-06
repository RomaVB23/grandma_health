<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWatchEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Bearer authentication is handled by route middleware.
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'device_id' => ['required', Rule::in([config('telemetry.device_id')])],
            'source' => ['required', Rule::in(['heartbeat', 'measurement'])],
            'received_at_ms' => ['required', 'integer', 'min:1'],
            'watch_sent_at_ms' => ['required', 'integer', 'min:1'],
            'bpm' => ['present', 'nullable', 'integer', 'between:1,1000'],
            'measured_at_ms' => ['present', 'nullable', 'integer', 'min:1'],
            'battery_percent' => ['present', 'nullable', 'integer', 'between:0,100'],
            'charging' => ['required', 'boolean'],
            'monitoring_status' => ['required', Rule::in([
                'active', 'starting', 'stopped', 'permission_lost', 'unsupported', 'error', 'unknown',
            ])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $now = (int) floor(microtime(true) * 1000);
            $tolerance = (int) config('telemetry.clock_tolerance_ms');
            foreach (['received_at_ms', 'watch_sent_at_ms', 'measured_at_ms'] as $key) {
                if ($this->input($key) !== null && (int) $this->input($key) > $now + $tolerance) {
                    $validator->errors()->add($key, 'Timestamp is ahead of the server clock.');
                }
            }

            if (($this->input('bpm') === null) !== ($this->input('measured_at_ms') === null)) {
                $validator->errors()->add('bpm', 'Pulse and measurement time must be provided together.');
            }
            if ($this->input('source') === 'measurement' && $this->input('bpm') === null) {
                $validator->errors()->add('bpm', 'A measurement event must contain a pulse.');
            }
            if ((int) $this->input('watch_sent_at_ms') > (int) $this->input('received_at_ms') + $tolerance) {
                $validator->errors()->add('watch_sent_at_ms', 'Watch time is ahead of phone reception time.');
            }
            if ($this->input('measured_at_ms') !== null &&
                (int) $this->input('measured_at_ms') > (int) $this->input('watch_sent_at_ms') + $tolerance) {
                $validator->errors()->add('measured_at_ms', 'Measurement time is ahead of watch send time.');
            }
        }];
    }

    public function payload(): array
    {
        $data = $this->validated();

        // Normalize before hashing: equivalent JSON retries keep the same identity.
        return [
            'event_id' => strtolower($data['event_id']),
            'device_id' => $data['device_id'],
            'source' => $data['source'],
            'received_at_ms' => (int) $data['received_at_ms'],
            'watch_sent_at_ms' => (int) $data['watch_sent_at_ms'],
            'bpm' => $data['bpm'] === null ? null : (int) $data['bpm'],
            'measured_at_ms' => $data['measured_at_ms'] === null ? null : (int) $data['measured_at_ms'],
            'battery_percent' => $data['battery_percent'] === null ? null : (int) $data['battery_percent'],
            'charging' => (bool) $data['charging'],
            'monitoring_status' => $data['monitoring_status'],
        ];
    }
}
