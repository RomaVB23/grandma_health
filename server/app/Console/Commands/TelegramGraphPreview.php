<?php

namespace App\Console\Commands;

use App\Services\Telegram\ChartImage;
use App\Services\Telegram\ChartReports;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Local preview only: never enqueue or contact Telegram. */
class TelegramGraphPreview extends Command
{
    protected $signature = 'telegram:graph-preview {--hours=6 : 6 or 12 hours} {--at= : ISO time of the period end}
        {--scheduled : Preview the latest 08:00/20:00 window} {--output= : PNG path; default data/telegram-chart-preview.png}';
    protected $description = 'Save a Telegram graph locally without sending messages or changing the schedule';

    public function handle(ChartReports $reports, ChartImage $image): int
    {
        if (!in_array((string) $this->option('hours'), ['6', '12'], true)) {
            $this->error('--hours must be 6 or 12');
            return self::FAILURE;
        }
        try {
            $timezone = config('telegram.timezone');
            $now = $this->option('at') ? CarbonImmutable::parse($this->option('at'), $timezone) : CarbonImmutable::now($timezone);
            $hours = (int) $this->option('hours');
            if ($this->option('scheduled')) {
                $slot = $reports->slot($now); $start = $slot['from_ms']; $end = $slot['to_ms']; $hours = 12;
            } else {
                $end = $now->getTimestampMs(); $start = $end - $hours * 3_600_000;
            }
            $chart = $reports->build($start, $end, $timezone, $hours);
            $path = $this->option('output') ?: base_path('data/telegram-chart-preview.png');
            if (file_put_contents($path, $image->render($chart), LOCK_EX) === false) throw new \RuntimeException('Cannot save PNG');
            $this->info('PNG: '.$path);
            $this->line($reports->caption($chart));
            $this->line('Telegram was not called; schedule and outbox were not changed.');
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Preview failed. Check the period, output directory and rebuilt server image (GD/fonts).');
            return self::FAILURE;
        }
    }
}
