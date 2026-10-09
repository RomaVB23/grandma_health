<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\DB;

/** State notices are independent of pulse/wearing thresholds, and respect opt-in. */
class ChargingNotifications
{
    public function tick(array $s, array &$state): void
    {
        if (($s['charging_state'] ?? 'unknown') === 'unknown') return;
        $charging = $s['charging_state'] === 'charging';
        $previous = $state['charging_seen'] ?? null;
        if ($previous === $charging) return;
        $state['charging_seen'] = $charging;
        $state['off_started_at'] = 0; // A full new grace period after unplugging.
        DB::table('telegram_outbox')->where('purpose', 'charging')->where('state', 'pending')
            ->where('alert_active', !$charging)->update(['state' => 'cancelled']);
        if (!$charging && $previous === null) return; // Initial off-charge is not an unplug event.
        if (!$charging) {
            $state['off_started_at'] = max($s['wearing_since_ms'] ?? 0,
                min(BotStore::now(), $s['charging_since_ms'] ?? BotStore::now()));
        }
        $body = $charging ? '🔌 Часы поставлены на зарядку. Наблюдение за пульсом в это время недоступно.'
            : '🔋 Часы сняты с зарядки. Наденьте их для продолжения наблюдения за пульсом.';
        $generation = DB::table('telemetry_epochs')->where('device_id', config('telemetry.device_id'))->value('generation') ?? 0;
        foreach (DB::table('telegram_members')->where('state', 'active')->where('alerts_allowed', true)
            ->where('notifications_enabled', true)->pluck('user_id') as $user) {
            app(BotStore::class)->enqueue((int) $user, $body, null, 'charging', [
                'event_key' => 'charging:'.$generation.':'.$s['charging_reported_at_ms'].':'.(int) $charging.':'.$user,
                'alert_kind' => 'charging', 'alert_active' => $charging,
            ]);
        }
    }
}
