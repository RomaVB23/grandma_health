<?php

namespace App\Services\Telegram;

use App\Services\WatchStatus;
use App\Services\TelemetryEpoch;
use Illuminate\Support\Facades\DB;

class TechnicalAlerts
{
    public function __construct(private BotStore $store, private WatchStatus $status, private TelemetryEpoch $epochs) {}

    public function tick(): void
    {
        DB::transaction(function (): void {
            $this->epochs->lock(config('telemetry.device_id'));
            $s = $this->status->snapshot();
            app(MonitoringAlerts::class)->tick($s);
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
            if (($s['phone_battery_percent'] ?? null) !== null && !($s['phone_battery_stale'] ?? true)) {
                $wasLow = (bool) DB::table('telegram_alerts')->where('kind', 'phone_battery')->value('active');
                $low = !$s['phone_charging'] && $s['phone_battery_percent'] < ($wasLow
                    ? config('telegram.battery_recovered_percent') : config('telegram.battery_low_percent') + 1);
                $this->update('phone_battery', $low,
                    '📱 Низкий заряд телефона: '.$s['phone_battery_percent'].'%. Поставьте Honor на зарядку: он передаёт данные с часов на сервер.',
                    '✅ Телефон заряжается или заряд восстановился до '.config('telegram.battery_recovered_percent').'% и выше.');
            }
        }, 5);
    }

    private function update(string $kind, bool $active, string $problem, string $recovery): void
    {
        app(AlertNotifications::class)->update($kind, $active, $problem, $recovery);
    }
}
