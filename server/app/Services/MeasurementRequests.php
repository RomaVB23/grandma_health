<?php

namespace App\Services;

use App\Services\Telegram\BotStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** One active request per watch; commands are never replayed after claim. */
class MeasurementRequests
{
    public const PENDING = ['queued', 'dispatched', 'measuring'];
    public const RESULTS = ['success', 'off_body', 'wearing_unknown', 'monitoring_stopped', 'permission_lost',
        'unsupported', 'sensor_error', 'timeout', 'busy', 'cooldown', 'cancelled', 'duplicate_request',
        'no_connection', 'multiple_watches', 'send_failed', 'invalid_result', 'clock_unavailable', 'phone_restarted'];

    public function create(?int $user = null): array
    {
        return DB::transaction(function () use ($user): array {
            app(TelemetryEpoch::class)->lock(config('telemetry.device_id'));
            $this->expire();
            $query = DB::table('measurement_requests')->where('device_id', config('telemetry.device_id'));
            $row = (clone $query)->whereIn('state', self::PENDING)->first();
            if (!$row) {
                $last = (clone $query)->orderByDesc('created_at_ms')->first();
                if ($last && BotStore::now() - $last->created_at_ms < 10_000) {
                    throw ValidationException::withMessages(['measurement' => 'Подождите 10 секунд между запросами.']);
                }
                $now = BotStore::now();
                $id = (string) Str::uuid();
                DB::table('measurement_requests')->insert(['id' => $id, 'device_id' => config('telemetry.device_id'),
                    'state' => 'queued', 'created_at_ms' => $now, 'dispatch_before_ms' => $now + 20_000,
                    'expires_at_ms' => $now + 100_000]);
                $row = DB::table('measurement_requests')->where('id', $id)->first();
            }
            if ($user !== null) DB::table('measurement_subscribers')->insertOrIgnore(['request_id' => $row->id, 'user_id' => $user]);
            return $this->present($row);
        }, 5);
    }

    public function claim(): ?array
    {
        if (!DB::table('measurement_requests')->where('device_id', config('telemetry.device_id'))->where('state', 'queued')->exists()) return null;
        return DB::transaction(function (): ?array {
            app(TelemetryEpoch::class)->lock(config('telemetry.device_id'));
            $this->expire();
            $row = DB::table('measurement_requests')->where('device_id', config('telemetry.device_id'))->where('state', 'queued')->first();
            if (!$row) return null;
            DB::table('measurement_requests')->where('id', $row->id)->update(['state' => 'dispatched']);
            $row->state = 'dispatched';
            return $this->present($row);
        }, 5);
    }

    public function finish(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            app(TelemetryEpoch::class)->lock(config('telemetry.device_id'));
            $this->expire();
            $row = $this->row($id);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if (!in_array($row->state, self::PENDING, true)) {
                if ($row->result_hash !== null && !hash_equals($row->result_hash, $hash)) abort(409);
                return $this->present($row); // Late replies acknowledge expiry without resurrecting success.
            }
            if ($row->state === 'queued') abort(409);
            if ($data['status'] === 'success') {
                $time = $data['measured_at_ms'];
                if ($time < $row->created_at_ms - config('telemetry.clock_tolerance_ms')
                    || $time > BotStore::now() + config('telemetry.clock_tolerance_ms')) {
                    throw ValidationException::withMessages(['measured_at_ms' => 'Result time is outside the request window.']);
                }
            }
            DB::table('measurement_requests')->where('id', $id)->update([
                'state' => $data['status'], 'finished_at_ms' => BotStore::now(), 'result_hash' => $hash,
                'bpm' => $data['bpm'] ?? null, 'measured_at_ms' => $data['measured_at_ms'] ?? null,
            ]);
            return $this->present($this->row($id));
        }, 5);
    }

    public function latest(): ?array
    {
        $this->expire();
        $row = DB::table('measurement_requests')->where('device_id', config('telemetry.device_id'))->orderByDesc('created_at_ms')->first();
        return $row ? $this->present($row) : null;
    }

    public function find(string $id): array { $this->expire(); return $this->present($this->row($id)); }

    public function expire(): void
    {
        $query = DB::table('measurement_requests')->where('device_id', config('telemetry.device_id'));
        (clone $query)->where('state', 'queued')->where('dispatch_before_ms', '<=', BotStore::now())
            ->update(['state' => 'phone_unavailable', 'finished_at_ms' => BotStore::now()]);
        (clone $query)->whereIn('state', self::PENDING)->where('expires_at_ms', '<=', BotStore::now())
            ->update(['state' => 'timeout', 'finished_at_ms' => BotStore::now()]);
    }

    public function notify(): void
    {
        DB::transaction(function (): void {
            app(TelemetryEpoch::class)->lock(config('telemetry.device_id'));
            $this->expire();
            $rows = DB::table('measurement_subscribers as s')->join('measurement_requests as r', 'r.id', '=', 's.request_id')
                ->whereNotIn('r.state', self::PENDING)->select('r.*', 's.user_id')->get();
            foreach ($rows as $row) {
                if (DB::table('telegram_members')->where('user_id', $row->user_id)->where('state', 'active')->exists()) {
                    app(BotStore::class)->enqueue($row->user_id, app(MeasurementText::class)->format($this->present($row)),
                        ['inline_keyboard' => [[['text' => '❤️ Измерить сейчас', 'callback_data' => 'measure']],
                            [['text' => '📊 Состояние', 'callback_data' => 'status']]]], 'measurement',
                        ['event_key' => 'measurement:'.$row->id.':'.$row->user_id]);
                }
                DB::table('measurement_subscribers')->where('request_id', $row->id)->where('user_id', $row->user_id)->delete();
            }
        }, 5);
    }

    private function row(string $id): object
    {
        return DB::table('measurement_requests')->where('id', $id)->where('device_id', config('telemetry.device_id'))->first() ?? abort(404);
    }

    private function present(object $row): array
    {
        return ['id' => $row->id, 'status' => $row->state, 'pending' => in_array($row->state, self::PENDING, true),
            'created_at_ms' => $row->created_at_ms, 'expires_at_ms' => $row->expires_at_ms,
            'remaining_ms' => max(0, $row->expires_at_ms - BotStore::now()),
            'bpm' => $row->bpm, 'measured_at_ms' => $row->measured_at_ms];
    }
}
