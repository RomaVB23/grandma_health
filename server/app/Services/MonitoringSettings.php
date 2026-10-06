<?php

namespace App\Services;

use App\Services\Telegram\BotStore;
use Illuminate\Support\Facades\DB;

class MonitoringSettings
{
    public function get(): object
    {
        return DB::table('monitoring_settings')->where('device_id', config('telemetry.device_id'))->first()
            ?? (object) ['pulse_enabled' => false, 'pulse_lower' => 60, 'pulse_upper' => 85,
                'confirmation_samples' => 1, 'pulse_max_age_seconds' => 300, 'confirmation_gap_minutes' => 15, 'wearing_enabled' => true,
                'off_wrist_minutes' => 10, 'changed_at_ms' => 0];
    }

    public function save(array $values, TelemetryEpoch $epochs, ?int $version = null): void
    {
        DB::transaction(function () use ($values, $epochs, $version): void {
            $device = config('telemetry.device_id');
            $epochs->lock($device);
            $current = $this->get();
            if ($version !== null && $current->changed_at_ms !== $version) {
                throw \Illuminate\Validation\ValidationException::withMessages(['version' => 'Настройки уже изменены. Обновите страницу.']);
            }
            DB::table('monitoring_settings')->updateOrInsert(['device_id' => $device], $values + [
                'changed_at_ms' => max(BotStore::now(), $current->changed_at_ms + 1),
            ]);
            $this->reset();
            // A configuration change is not a physiological recovery.
            DB::table('telegram_alerts')->whereIn('kind', ['pulse', 'wearing'])->update([
                'active' => false, 'generation' => DB::raw('generation + 1'),
            ]);
            DB::table('telegram_outbox')->where('state', 'pending')->whereIn('alert_kind', ['pulse', 'wearing'])
                ->update(['state' => 'cancelled']);
        }, 5);
    }

    public function reset(): void
    {
        DB::table('monitoring_state')->where('device_id', config('telemetry.device_id'))->delete();
    }
}
