<?php

namespace App\Services\Telegram;

use App\Services\PulseChartData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ChartReports
{
    public function __construct(private BotStore $store, private PulseChartData $data, private ChartImage $image) {}

    public function request(int $user): bool
    {
        $now = BotStore::now();
        $key = 'chart_requested:'.$user;
        if ($now - (int) $this->store->value($key, '0') < 30_000) return false;
        $this->queue($user, $now - 6 * 3_600_000, $now, config('telegram.timezone'), false);
        $this->store->put($key, (string) $now);
        return true;
    }

    /** Latest scheduled boundary. Reports use adjacent half-open 12-hour periods. */
    public function slot(?CarbonImmutable $now = null): array
    {
        $timezone = config('telegram.timezone');
        $now = ($now ?? CarbonImmutable::createFromTimestampMs(BotStore::now(), $timezone))->setTimezone($timezone);
        $end = $now->setTime(20, 0);
        if ($now->hour < 20) $end = $now->setTime(8, 0);
        if ($now->hour < 8) $end = $now->subDay()->setTime(20, 0);
        // Wall-clock boundaries stay at 08:00/20:00, including DST zones.
        $start = $end->hour === 8 ? $end->subDay()->setTime(20, 0) : $end->setTime(8, 0);
        return ['from_ms' => $start->getTimestampMs(), 'to_ms' => $end->getTimestampMs(), 'timezone' => $timezone,
            'key' => $timezone.':'.$end->getTimestampMs()];
    }

    public function tick(): void
    {
        if (!config('telegram.charts_enabled')) return;
        $slot = $this->slot();
        DB::transaction(function () use ($slot): void {
            // Writing before reading serializes both scheduling and outbox effects.
            DB::table('telegram_state')->insertOrIgnore(['key' => 'chart_schedule_slot', 'value' => '']);
            DB::table('telegram_state')->where('key', 'chart_schedule_slot')->update(['value' => DB::raw('value')]);
            $last = $this->store->value('chart_schedule_slot');
            if ($last === $slot['key']) return;
            if ($last === '') {
                // First activation does not send old reports. The next boundary starts delivery.
                $this->store->put('chart_schedule_slot', $slot['key']);
                return;
            }
            // Following a pause send only the latest completed window, not a backlog.
            DB::table('telegram_outbox')->where('purpose', 'chart_scheduled')->where('state', 'pending')
                ->update(['state' => 'cancelled']);
            foreach (DB::table('telegram_members')->where('state', 'active')->where('alerts_allowed', true)
                ->where('notifications_enabled', true)->pluck('user_id') as $user) {
                $this->queue((int) $user, $slot['from_ms'], $slot['to_ms'], $slot['timezone'], true,
                    'chart:'.$slot['key'].':'.$user);
            }
            $this->store->put('chart_schedule_slot', $slot['key']);
        }, 5);
    }

    private function queue(int $user, int $start, int $end, string $timezone, bool $scheduled, ?string $key = null): void
    {
        $this->store->enqueue($user, '', null, $scheduled ? 'chart_scheduled' : 'chart', [
            'event_key' => $key,
            'chart' => json_encode(['from_ms' => $start, 'to_ms' => $end, 'timezone' => $timezone,
                'hours' => $scheduled ? 12 : 6], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function build(int $start, int $end, string $timezone, int $hours): array
    {
        return $this->data->build($start, $end, $timezone, 600_000, true) + ['hours' => $hours];
    }

    public function caption(array $chart): string
    {
        $at = fn (int $ms) => CarbonImmutable::createFromTimestampMs($ms, $chart['timezone'])->format('d.m.Y H:i:s');
        $pulse = $chart['report']['pulse'];
        $body = "📈 Пульс за {$chart['hours']} часов\n".$at($chart['from_ms']).' — '.$at($chart['to_ms'])
            .' · '.$chart['timezone']."\n\n";
        if (!$pulse['count']) $body .= 'За этот период нет полученных замеров.';
        else $body .= "Уникальных замеров: {$pulse['count']}\nДиапазон: {$pulse['min_bpm']}–{$pulse['max_bpm']} уд/мин\n"
            .'Среднее полученных замеров: '.str_replace('.', ',', (string) $pulse['mean_bpm']).' уд/мин';
        return $body."\n\nОранжевым — часы на зарядке; серым — остальные длительные паузы без замеров."
            ." Без времени подключения границы зарядки приблизительные. Пульс между точками неизвестен.";
    }

    public function send(object $message, TelegramApi $api): void
    {
        $spec = json_decode($message->chart, true, flags: JSON_THROW_ON_ERROR);
        $chart = $this->build($spec['from_ms'], $spec['to_ms'], $spec['timezone'], $spec['hours']);
        $api->photo(['chat_id' => $message->user_id, 'caption' => $this->caption($chart)], $this->image->render($chart));
    }
}
