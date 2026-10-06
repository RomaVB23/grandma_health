<?php

namespace App\Services;

use App\Services\Telegram\BotStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TelemetryEpoch
{
    public function current(string $device): object
    {
        return DB::table('telemetry_epochs')->where('device_id', $device)->first()
            ?? (object) ['cleared_before_ms' => 0, 'generation' => 0];
    }

    public function lock(string $device): object
    {
        // Must be the FIRST database statement inside the transaction. Even a
        // no-op INSERT obtains SQLite's write lock before reading the boundary.
        // Ingestion, clearing and alert evaluation therefore cannot race a reset.
        DB::table('telemetry_epochs')->insertOrIgnore(['device_id' => $device]);
        return $this->current($device);
    }

    public function clear(string $device, int $expectedGeneration): array
    {
        return DB::transaction(function () use ($device, $expectedGeneration): array {
            $epoch = $this->lock($device);
            if ($epoch->generation !== $expectedGeneration) {
                throw ValidationException::withMessages(['confirmation' => 'История уже была очищена. Откройте новый экран подтверждения.']);
            }
            $now = BotStore::now();
            $boundary = max($now, $epoch->cleared_before_ms + 1);
            DB::table('telemetry_epochs')->where('device_id', $device)->update([
                'cleared_before_ms' => $boundary, 'generation' => $epoch->generation + 1,
            ]);
            $count = DB::table('watch_events')->where('device_id', $device)->delete();
            // Keep membership, invites, polling cursor and notification preferences.
            // Advance incident generations so old delivered warnings cannot create
            // a spurious recovery or collide with the next incident's event key.
            DB::table('telegram_alerts')->update(['active' => false, 'generation' => DB::raw('generation + 1')]);
            DB::table('telegram_outbox')->where('state', 'pending')->whereIn('purpose', ['alert', 'status'])
                ->update(['state' => 'cancelled']);
            DB::table('telegram_state')->updateOrInsert(['key' => 'alerts_started_at'], ['value' => (string) $now]);
            return ['count' => $count, 'cleared_at_ms' => $boundary];
        }, 5);
    }
}
