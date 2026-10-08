<?php

namespace App\Services\Telegram;

use App\Services\WatchStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BotDelivery
{
    public function __construct(private TelegramApi $api, private WatchStatus $status, private StatusText $text) {}

    public function flush(): void
    {
        $deadline = microtime(true) + 8;
        $messages = DB::table('telegram_outbox')->where('state', 'pending')->where('available_at_ms', '<=', BotStore::now())
            ->orderByRaw("CASE WHEN purpose='alert' THEN 0 ELSE 1 END")->orderBy('id')->limit(100)->get();
        foreach ($messages as $message) {
            if (microtime(true) >= $deadline) { return; }
            $member = DB::table('telegram_members')->where('user_id', $message->user_id)->first();
            $allowed = $message->purpose === 'access' || $member?->state === 'active';
            if ($message->purpose === 'alert') {
                $alert = DB::table('telegram_alerts')->where('kind', $message->alert_kind)->first();
                $allowed = $allowed && $member?->alerts_allowed && $member?->notifications_enabled
                    && $alert && (bool) $alert->active === (bool) $message->alert_active
                    && $alert->generation === $message->alert_generation;
                if ($allowed && $message->alert_kind === 'phone_battery') {
                    $s = $this->status->snapshot();
                    $percent = $s['phone_battery_percent'] ?? null;
                    $low = $percent !== null && !($s['phone_charging'] ?? false)
                        && $percent < config('telegram.battery_recovered_percent');
                    $allowed = $percent !== null && !($s['phone_battery_stale'] ?? true)
                        && $low === (bool) $message->alert_active;
                }
                if ($allowed && in_array($message->alert_kind, ['pulse', 'wearing'], true)) {
                    $s = $this->status->snapshot();
                    $rules = app(\App\Services\MonitoringSettings::class)->effective();
                    if ($message->alert_kind === 'pulse') {
                        $state = json_decode(DB::table('monitoring_state')->where('device_id', config('telemetry.device_id'))
                            ->value('state') ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                        $allowed = ($state['profile_key'] ?? '') === $rules->profile_key && ($s['pulse_eligible'] ?? false)
                            && \App\Services\MonitoringEligibility::outside($s['bpm'], $rules) === (bool) $message->alert_active;
                    } else {
                        $allowed = $rules->wearing_enabled && $s['contact_recent'] && $s['monitoring_status'] === 'active'
                            && ($s['wearing_state'] ?? 'unknown') === ($message->alert_active ? 'off' : 'on');
                    }
                }
            }
            if (!$allowed) {
                DB::table('telegram_outbox')->where('id', $message->id)->update(['state' => 'cancelled']);
                continue;
            }
            $now = BotStore::now();
            $lastUserSend = DB::table('telegram_outbox')->where('user_id', $message->user_id)->max('sent_at_ms');
            $lastGlobalSend = DB::table('telegram_outbox')->max('sent_at_ms');
            if (($lastUserSend !== null && $now - $lastUserSend < 1100) ||
                ($lastGlobalSend !== null && $now - $lastGlobalSend < 60)) {
                continue;
            }
            $payload = ['chat_id' => $message->user_id,
                'text' => $message->purpose === 'status' ? $this->text->format($this->status->snapshot()) : $message->body,
                'link_preview_options' => ['is_disabled' => true]];
            if ($message->markup !== null) { $payload['reply_markup'] = json_decode($message->markup, true, flags: JSON_THROW_ON_ERROR); }
            try {
                $this->api->call('sendMessage', $payload);
                DB::table('telegram_outbox')->where('id', $message->id)->update(['state' => 'sent', 'sent_at_ms' => BotStore::now()]);
            } catch (TelegramApiException $error) {
                if ($error->apiCode === 401) { throw $error; }
                if ($error->apiCode === 403) {
                    DB::table('telegram_members')->where('user_id', $message->user_id)->update(['notifications_enabled' => false]);
                }
                $permanent = in_array($error->apiCode, [400, 403], true);
                Log::warning('Telegram delivery failed', ['code' => $error->apiCode, 'permanent' => $permanent]);
                $delay = $error->apiCode === 429 ? $error->retryAfter : min(300, 15 * (2 ** min(4, $message->attempts)));
                DB::table('telegram_outbox')->where('id', $message->id)->update([
                    'state' => $permanent ? 'failed' : 'pending', 'attempts' => $message->attempts + 1,
                    'available_at_ms' => BotStore::now() + $delay * 1000,
                ]);
                if ($error->apiCode === 429) { return; }
            }
        }
    }
}
