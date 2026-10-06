<?php

namespace App\Services\Telegram;

use App\Services\WatchStatus;
use Illuminate\Support\Facades\DB;

class TechnicalAlerts
{
    public function __construct(private BotStore $store, private WatchStatus $status) {}

    public function tick(): void
    {
        $s = $this->status->snapshot();
        DB::transaction(function () use ($s): void {
            $now = BotStore::now();
            if ($this->store->value('alerts_started_at') === '') {
                $this->store->put('alerts_started_at', (string) $now);
            }
            $initialGrace = $s['last_live_contact_at_ms'] === null
                && $now - (int) $this->store->value('alerts_started_at') < config('telemetry.contact_timeout_ms');
            if (!$initialGrace) {
                $this->update('contact', !$s['contact_recent'],
                    '⚠️ Нет свежего сигнала связи с часами 10 минут или более. Проверьте часы, Honor, сеть и сервер. Это не подтверждение проблемы со здоровьем.',
                    '✅ Свежие сигналы связи с часами снова поступают.');
            }
            $freshBattery = $s['battery_percent'] !== null && $s['snapshot_at_ms'] !== null
                && $s['snapshot_at_ms'] <= $s['server_time_ms'] + config('telemetry.clock_tolerance_ms')
                && $s['server_time_ms'] - $s['snapshot_at_ms'] < config('telegram.battery_fresh_ms');
            if ($freshBattery) {
                $wasLow = (bool) DB::table('telegram_alerts')->where('kind', 'battery')->value('active');
                $low = !$s['charging'] && $s['battery_percent'] < ($wasLow
                    ? config('telegram.battery_recovered_percent') : config('telegram.battery_low_percent') + 1);
                $this->update('battery', $low,
                    '🔋 Низкий заряд часов: '.$s['battery_percent'].'%. Поставьте часы на зарядку.',
                    '✅ Часы заряжаются или заряд восстановился до 25% и выше.');
            }
        });
    }

    private function update(string $kind, bool $active, string $problem, string $recovery): void
    {
        DB::table('telegram_alerts')->insertOrIgnore(['kind' => $kind]);
        $alert = DB::table('telegram_alerts')->where('kind', $kind)->first();
        if ((bool) $alert->active !== $active) {
            $generation = $active ? $alert->generation + 1 : $alert->generation;
            DB::table('telegram_alerts')->where('kind', $kind)->update(['active' => $active, 'generation' => $generation]);
            $alert = DB::table('telegram_alerts')->where('kind', $kind)->first();
        }
        if ($alert->generation === 0) { return; }
        $subscribers = DB::table('telegram_members')->where('state', 'active')
            ->where('alerts_allowed', true)->where('notifications_enabled', true)->get();
        foreach ($subscribers as $member) {
            // Recovery is only sent to someone who actually received this incident's warning.
            if (!$active && !DB::table('telegram_outbox')->where('user_id', $member->user_id)
                ->where('alert_kind', $kind)->where('alert_generation', $alert->generation)
                ->where('alert_active', true)->where('state', 'sent')->exists()) {
                continue;
            }
            $key = 'alert:'.$kind.':'.$alert->generation.':'.(int) $active.':'.$member->user_id;
            // A user opting back in may receive a warning cancelled before delivery.
            DB::table('telegram_outbox')->where('event_key', $key)->where('state', 'cancelled')
                ->whereNull('sent_at_ms')->update(['state' => 'pending', 'available_at_ms' => 0]);
            $this->store->enqueue($member->user_id, $active ? $problem : $recovery, null, 'alert', [
                'event_key' => $key,
                'alert_kind' => $kind, 'alert_generation' => $alert->generation, 'alert_active' => $active,
            ]);
        }
    }
}
