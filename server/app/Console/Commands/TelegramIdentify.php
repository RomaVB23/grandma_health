<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramApi;
use Illuminate\Console\Command;
use Throwable;

class TelegramIdentify extends Command
{
    protected $signature = 'telegram:identify';
    protected $description = 'Identify the owner through a one-time setup link; run before starting the worker';

    public function handle(TelegramApi $api): int
    {
        if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/D', (string) config('telegram.token'))) {
            $this->error('Set TELEGRAM_BOT_TOKEN in the local .env first.');
            return self::FAILURE;
        }
        $lock = fopen(database_path('../data/telegram-worker.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Stop the Telegram worker before identifying an account.');
            return self::FAILURE;
        }
        try {
            $me = $api->call('getMe');
            $nonce = 'setup_'.bin2hex(random_bytes(16));
            $this->line('Open this link in YOUR Telegram account and press Start:');
            $this->line('https://t.me/'.$me['username'].'?start='.$nonce);
            $this->line('Waiting up to 2 minutes. Do not forward this setup link to anyone.');
            $deadline = microtime(true) + 120;
            $offset = 0;
            while (microtime(true) < $deadline) {
                $updates = $api->call('getUpdates', ['offset' => $offset, 'timeout' => 15, 'allowed_updates' => ['message']]);
                foreach (is_array($updates) ? $updates : [] as $update) {
                    $offset = $update['update_id'] + 1;
                    $m = $update['message'] ?? [];
                    if (($m['text'] ?? '') === '/start '.$nonce && ($m['chat']['type'] ?? '') === 'private'
                        && is_int($m['from']['id'] ?? null) && $m['from']['id'] > 0
                        && ($m['chat']['id'] ?? null) === ($m['from']['id'] ?? null) && !($m['from']['is_bot'] ?? true)) {
                        $this->info('TELEGRAM_OWNER_ID='.$m['from']['id']);
                        $this->line('Copy this ID into the local .env. Identification is complete; the bot is not running yet.');
                        return self::SUCCESS;
                    }
                }
                $this->line('Still waiting for the setup link to be opened...');
            }
            $this->error('No matching setup message received. Run the command again for a new link.');
        } catch (Throwable) {
            $this->error('Identification failed. Check the token and Internet connection; stop any other worker for this bot.');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return self::FAILURE;
    }
}
