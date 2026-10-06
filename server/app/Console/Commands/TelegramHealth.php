<?php

namespace App\Console\Commands;

use App\Services\Telegram\BotStore;
use Illuminate\Console\Command;

class TelegramHealth extends Command
{
    protected $signature = 'telegram:health';
    protected $description = 'Check that the Telegram polling loop is making progress';

    public function handle(BotStore $store): int
    {
        $age = BotStore::now() - (int) $store->value('worker_tick_at', '0');
        return $age >= 0 && $age < 150_000 ? self::SUCCESS : self::FAILURE;
    }
}
