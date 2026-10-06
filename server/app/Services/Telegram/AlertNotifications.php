<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\DB;

class AlertNotifications
{
    public function __construct(private BotStore $store) {}

    public function update(string $kind, bool $active, string $problem, string $recovery): void
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
            DB::table('telegram_outbox')->where('event_key', $key)->where('state', 'pending')
                ->update(['body' => $active ? $problem : $recovery]);
            $this->store->enqueue($member->user_id, $active ? $problem : $recovery, null, 'alert', [
                'event_key' => $key,
                'alert_kind' => $kind, 'alert_generation' => $alert->generation, 'alert_active' => $active,
            ]);
        }
    }
}
