<?php

namespace App\Services\Telegram;

use App\Services\MonitoringEligibility;
use App\Services\MonitoringSettings;
use Illuminate\Support\Facades\DB;

/** Called inside the same epoch-locked transaction as technical alerts. */
class MonitoringAlerts
{
    public function __construct(private MonitoringSettings $settings, private AlertNotifications $notifications) {}

    public function tick(array $s): void
    {
        $device = config('telemetry.device_id');
        $now = BotStore::now();
        $rules = $this->settings->effective($now);
        $row = DB::table('monitoring_state')->where('device_id', $device)->first();
        $state = $row ? json_decode($row->state, true, flags: JSON_THROW_ON_ERROR) : [];
        $state += ['last_measurement' => $rules->changed_at_ms, 'candidate' => '', 'count' => 0,
            'candidate_at' => 0, 'off_started_at' => 0];
        if (($state['profile_key'] ?? '') !== $rules->profile_key) {
            $state['profile_key'] = $rules->profile_key;
            $state['candidate'] = ''; $state['count'] = 0; $state['candidate_at'] = 0;
            $state['last_measurement'] = max($state['last_measurement'], $rules->changed_at_ms);
            // A calendar/mode change ends the old rule episode without claiming recovery.
            // Wearing timers and technical alert incidents remain independent.
            DB::table('telegram_alerts')->where('kind', 'pulse')->update([
                'active' => false, 'generation' => DB::raw('generation + 1'),
            ]);
            $this->cancelPending('pulse');
        }
        $confirmationGap = $rules->confirmation_gap_minutes * 60_000;
        // Retain an accepted sample as evidence, even when its current reading
        // becomes stale. The timeout is between neighbouring confirmations.
        if ($state['count'] > 0 && $state['candidate_at'] < ($s['wearing_since_ms'] ?? 0)) {
            $state['candidate'] = ''; $state['count'] = 0;
        }
        $worn = $s['wearing_state'] ?? 'unknown';
        $live = $s['contact_recent'] && $s['monitoring_status'] === 'active';
        app(ChargingNotifications::class)->tick($s, $state);
        $charging = ($s['charging_state'] ?? 'unknown') === 'charging';
        if ($charging) {
            $state['off_started_at'] = 0;
            $this->cancelPending('wearing');
            // Charging ends the off-wrist incident without a false "worn again" notice.
            DB::table('telegram_alerts')->where('kind', 'wearing')->where('active', true)
                ->update(['active' => false, 'generation' => DB::raw('generation + 1')]);
        }

        if ($rules->wearing_enabled && $live && $worn === 'off' && !$charging) {
            if ($state['off_started_at'] === 0) $state['off_started_at'] = $now;
            if ($now - $state['off_started_at'] >= $rules->off_wrist_minutes * 60_000) {
                $this->notifications->update('wearing', true,
                    '⌚ Часы определяются как снятые '.$rules->off_wrist_minutes.' минут или более. Наблюдение за пульсом недоступно. Проверьте, надеты ли часы.',
                    '✅ Часы снова определяются как надетые. Для контроля пульса ожидаем новое измерение.');
            }
        } else {
            $state['off_started_at'] = 0;
            if ($rules->wearing_enabled && $live && $worn === 'on' && !$charging) {
                $this->notifications->update('wearing', false, '',
                    '✅ Часы снова определяются как надетые. Для контроля пульса ожидаем новое измерение.');
            } else {
                $this->cancelPending('wearing'); // Unknown is not a recovery.
            }
        }

        if (!$rules->pulse_enabled || !$live || $worn !== 'on' || $charging) {
            $state['candidate'] = ''; $state['count'] = 0;
            $state['last_measurement'] = max($state['last_measurement'], $s['measured_at_ms'] ?? 0);
            $this->cancelPending('pulse');
        } else {
            // Ordered distinct samples, including a heartbeat's first copy of a missing
            // measurement. UUID retries and copies never count as another measurement.
            $events = DB::table('watch_events')->where('device_id', $device)->whereNotNull('bpm')
                ->where('measured_at_ms', '>', $state['last_measurement'])
                ->where('measured_at_ms', '>', $now - $rules->pulse_max_age_seconds * 1000)
                ->orderBy('measured_at_ms')->orderBy('id')->limit(1500)->get();
            foreach ($events as $event) {
                if ($event->measured_at_ms <= $state['last_measurement']) continue;
                $state['last_measurement'] = $event->measured_at_ms;
                if (!MonitoringEligibility::pulse($event, $s, $rules, $now)) {
                    $state['candidate'] = ''; $state['count'] = 0;
                    continue;
                }
                $outside = MonitoringEligibility::outside($event->bpm, $rules);
                $candidate = $outside ? 'outside' : 'inside';
                if ($candidate !== $state['candidate'] || $event->measured_at_ms - $state['candidate_at'] > $confirmationGap) {
                    $state['count'] = 0;
                }
                $state['candidate'] = $candidate;
                $state['candidate_at'] = $event->measured_at_ms;
                $state['count'] = min($rules->confirmation_samples, $state['count'] + 1);
                if ($state['count'] >= $rules->confirmation_samples) {
                    $this->notifications->update('pulse', $outside, $this->problem($event, $rules), $this->recovery($event, $rules));
                }
            }
            // Only discard the idle tail once even a fresh, slightly delayed
            // sample measured within its gap could no longer arrive. The gap
            // above is still measured between samples, not between worker ticks.
            if ($state['count'] > 0 && $now - $state['candidate_at'] >=
                $confirmationGap + $rules->pulse_max_age_seconds * 1000) {
                $state['candidate'] = ''; $state['count'] = 0;
            }
            // Only repeat the pending *delivery*, not the warning, while current
            // evidence still supports the active episode (e.g. a new subscriber).
            $active = (bool) DB::table('telegram_alerts')->where('kind', 'pulse')->value('active');
            if ($active && ($s['pulse_eligible'] ?? false) && MonitoringEligibility::outside($s['bpm'], $rules)) {
                $this->notifications->update('pulse', true, $this->problem((object) $s, $rules), '');
            } elseif (!($s['pulse_eligible'] ?? false)) {
                // Staleness cancels delivery, not a still-valid confirmation
                // sequence. Only a newly accepted sample can complete it.
                $this->cancelPending('pulse');
            }
        }
        DB::table('monitoring_state')->updateOrInsert(['device_id' => $device], ['state' => json_encode($state, JSON_THROW_ON_ERROR)]);
    }

    private function problem(object $event, object $rules): string
    {
        return '⚠️ Пульс по показаниям часов вне заданного диапазона: '.$event->bpm.' уд/мин.'
            .' Границы: '.$rules->pulse_lower.'–'.$rules->pulse_upper.' уд/мин.'
            .' Профиль: '.\App\Services\MonitoringProfile::label($rules->effective_profile).'.'
            .' Измерен: '.$this->time($event->measured_at_ms).'. Проверьте самочувствие и показания.';
    }

    private function recovery(object $event, object $rules): string
    {
        return '✅ Пульс по новым показаниям часов вернулся в заданный диапазон: '.$event->bpm.' уд/мин.'
            .' Границы: '.$rules->pulse_lower.'–'.$rules->pulse_upper.' уд/мин.'
            .' Профиль: '.\App\Services\MonitoringProfile::label($rules->effective_profile).'.'
            .' Измерен: '.$this->time($event->measured_at_ms).'.';
    }

    private function time(int $ms): string
    {
        return \Carbon\CarbonImmutable::createFromTimestampMs($ms)->setTimezone(config('telegram.timezone'))->format('d.m.Y H:i:s');
    }

    private function cancelPending(string $kind): void
    {
        DB::table('telegram_outbox')->where('state', 'pending')->where('alert_kind', $kind)->update(['state' => 'cancelled']);
    }
}
