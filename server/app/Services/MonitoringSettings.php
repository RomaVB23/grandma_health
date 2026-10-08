<?php

namespace App\Services;

use App\Services\Telegram\BotStore;
use Illuminate\Support\Facades\DB;

class MonitoringSettings
{
    public function get(): object
    {
        $row = DB::table('monitoring_settings')->where('device_id', config('telemetry.device_id'))->first();
        return (object) ((array) $row + ['pulse_enabled' => false, 'pulse_lower' => 60, 'pulse_upper' => 85,
                'confirmation_samples' => 1, 'pulse_max_age_seconds' => 300, 'confirmation_gap_minutes' => 15, 'wearing_enabled' => true,
                'off_wrist_minutes' => 10, 'changed_at_ms' => 0,
                'night_pulse_lower' => $row?->pulse_lower ?? 60, 'night_pulse_upper' => $row?->pulse_upper ?? 85,
                'night_start' => '23:00', 'night_end' => '08:00', 'profile_timezone' => config('telegram.timezone'),
                'profile_mode' => 'auto', 'profile_override_until_ms' => null]);
    }

    public function effective(?int $now = null): object
    {
        $rules = $this->get();
        $p = app(MonitoringProfile::class)->resolve($rules, $now ?? BotStore::now());
        $rules->pulse_lower = $p['profile'] === 'night' ? $rules->night_pulse_lower : $rules->pulse_lower;
        $rules->pulse_upper = $p['profile'] === 'night' ? $rules->night_pulse_upper : $rules->pulse_upper;
        $rules->changed_at_ms = $p['from_ms'];
        $rules->effective_profile = $p['profile'];
        $rules->effective_mode = $p['mode'];
        $rules->profile_key = $p['key'];
        $rules->profile_until_ms = $p['until_ms'];
        $rules->effective_override_until_ms = $p['override_until_ms'];
        return $rules;
    }

    public function description(): string
    {
        $r = $this->get(); $p = $this->effective();
        $until = \Carbon\CarbonImmutable::createFromTimestampMs($p->profile_until_ms)
            ->setTimezone($r->profile_timezone)->format('d.m.Y H:i');
        return '🕒 Профиль: '.MonitoringProfile::label($p->effective_profile)
            .($p->effective_mode === 'auto' ? ' · по расписанию' : ' · вручную')
            .' · границы '.$p->pulse_lower.'–'.$p->pulse_upper.' уд/мин.'
            ."\nКонтроль пульса: ".($r->pulse_enabled ? 'включён' : 'выключен')
            ."\nНочной период: ".$r->night_start.'–'.$r->night_end.' · '.$r->profile_timezone
            ."\n".($p->effective_mode === 'auto' ? 'Следующая смена профиля: ' : 'Возврат к расписанию: ').$until;
    }

    public function setMode(string $mode, TelemetryEpoch $epochs, int $version): void
    {
        if (!in_array($mode, ['auto', 'day', 'night'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['mode' => 'Неизвестный режим.']);
        }
        $now = BotStore::now();
        $r = $this->get();
        $scheduled = clone $r; $scheduled->profile_mode = 'auto';
        $scheduled->profile_override_until_ms = null;
        $until = app(MonitoringProfile::class)->resolve($scheduled, $now)['until_ms'];
        $this->save(['profile_mode' => $mode, 'profile_override_until_ms' => $mode === 'auto' ? null : $until], $epochs, $version, true);
    }

    public function save(array $values, TelemetryEpoch $epochs, ?int $version = null, bool $pulseOnly = false): void
    {
        DB::transaction(function () use ($values, $epochs, $version, $pulseOnly): void {
            $device = config('telemetry.device_id');
            $epochs->lock($device);
            $current = $this->get();
            if ($version !== null && $current->changed_at_ms !== $version) {
                throw \Illuminate\Validation\ValidationException::withMessages(['version' => 'Настройки уже изменены. Обновите страницу.']);
            }
            if (array_intersect(['night_start', 'night_end', 'profile_timezone'], array_keys($values))
                && !array_key_exists('profile_mode', $values)) {
                $candidate = (object) array_replace((array) $current, $values);
                $activeMode = $this->effective()->effective_mode;
                $candidate->profile_mode = 'auto'; $candidate->profile_override_until_ms = null;
                $values['profile_mode'] = $activeMode;
                $values['profile_override_until_ms'] = $activeMode === 'auto' ? null
                    : app(MonitoringProfile::class)->resolve($candidate, BotStore::now())['until_ms'];
            }
            DB::table('monitoring_settings')->updateOrInsert(['device_id' => $device], $values + [
                'changed_at_ms' => max(BotStore::now(), $current->changed_at_ms + 1),
            ]);
            if ($pulseOnly) {
                $row = DB::table('monitoring_state')->where('device_id', $device)->first();
                if ($row) {
                    $state = json_decode($row->state, true, flags: JSON_THROW_ON_ERROR);
                    $effective = $this->effective();
                    $state['profile_key'] = $effective->profile_key;
                    $state['candidate'] = ''; $state['count'] = 0; $state['candidate_at'] = 0;
                    $state['last_measurement'] = max($state['last_measurement'] ?? 0, $effective->changed_at_ms);
                    DB::table('monitoring_state')->where('device_id', $device)->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);
                }
            } else { $this->reset(); }
            $kinds = $pulseOnly ? ['pulse'] : ['pulse', 'wearing'];
            // A configuration change is not a physiological recovery.
            DB::table('telegram_alerts')->whereIn('kind', $kinds)->update([
                'active' => false, 'generation' => DB::raw('generation + 1'),
            ]);
            DB::table('telegram_outbox')->where('state', 'pending')->whereIn('alert_kind', $kinds)
                ->update(['state' => 'cancelled']);
        }, 5);
    }

    public function reset(): void
    {
        DB::table('monitoring_state')->where('device_id', config('telemetry.device_id'))->delete();
    }
}
