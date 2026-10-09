<?php

namespace App\Console\Commands;

use App\Services\Telegram\BotDelivery;
use App\Services\Telegram\BotHandler;
use App\Services\Telegram\BotStore;
use App\Services\Telegram\TechnicalAlerts;
use App\Services\Telegram\TelegramApi;
use App\Services\Telegram\TelegramApiException;
use DateTimeZone;
use Illuminate\Console\Command;
use Throwable;

class TelegramRun extends Command
{
    protected $signature = 'telegram:run';
    protected $description = 'Run the private Telegram bot using outgoing long polling';

    public function handle(TelegramApi $api, BotStore $store, BotHandler $handler, BotDelivery $delivery, TechnicalAlerts $alerts): int
    {
        if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/D', (string) config('telegram.token'))
            || !preg_match('/^[1-9]\d{0,15}$/D', (string) config('telegram.owner_id'))) {
            $this->error('Configure TELEGRAM_BOT_TOKEN and your TELEGRAM_OWNER_ID in the local .env.');
            return self::FAILURE;
        }
        try { new DateTimeZone(config('telegram.timezone')); }
        catch (Throwable) { $this->error('Invalid TELEGRAM_TIMEZONE.'); return self::FAILURE; }
        $lock = fopen(database_path('../data/telegram-worker.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Another Telegram worker is already running.');
            return self::FAILURE;
        }
        $running = true;
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$running): void { $running = false; });
            pcntl_signal(SIGINT, function () use (&$running): void { $running = false; });
        }
        try {
            $me = $api->call('getMe');
            if (!is_array($me) || empty($me['username'])) { throw new \RuntimeException('Invalid getMe response.'); }
            $webhook = $api->call('getWebhookInfo');
            if (!empty($webhook['url'])) {
                $this->error('This bot has a webhook. Use a new bot or remove the webhook before long polling.');
                return self::FAILURE;
            }
            config(['telegram.username' => $me['username']]);
            $store->ensureOwner();
            $this->info('Telegram worker started: @'.$me['username']);
            while ($running) {
                try {
                    $store->put('worker_tick_at', (string) BotStore::now());
                    $alerts->tick();
                    app(\App\Services\MeasurementRequests::class)->notify();
                    app(\App\Services\Telegram\ChartReports::class)->tick();
                    $delivery->flush();
                    if (!$running) { break; }
                    $pending = \Illuminate\Support\Facades\DB::table('telegram_outbox')->where('state', 'pending')
                        ->where('available_at_ms', '<=', BotStore::now())->exists();
                    $updates = $api->call('getUpdates', ['offset' => (int) $store->value('update_id', '-1') + 1,
                        'limit' => 50, 'timeout' => ($pending || \Illuminate\Support\Facades\DB::table('measurement_subscribers')->exists()) ? 1 : 20, 'allowed_updates' => ['message', 'callback_query']]);
                    foreach (is_array($updates) ? $updates : [] as $update) {
                        if (!$running) { break; }
                        $handler->handle($update);
                        if (isset($update['callback_query']['id'])) {
                            try { $api->call('answerCallbackQuery', ['callback_query_id' => $update['callback_query']['id']]); }
                            catch (TelegramApiException) { /* Old buttons may no longer be answerable. */ }
                        }
                    }
                } catch (TelegramApiException $error) {
                    $this->warn($error->getMessage());
                    if (in_array($error->apiCode, [401, 409], true)) { return self::FAILURE; }
                    $until = microtime(true) + ($error->apiCode === 429 ? $error->retryAfter : 10);
                    while ($running && microtime(true) < $until) {
                        $store->put('worker_tick_at', (string) BotStore::now());
                        sleep((int) min(10, max(1, ceil($until - microtime(true)))));
                    }
                } catch (Throwable) {
                    // Full exception traces could expose credentials or medical data.
                    $this->warn('Telegram worker error. State is retained; retrying in 10 seconds.');
                    if ($running) { sleep(10); }
                }
            }
        } catch (Throwable) {
            $this->error('Telegram startup failed. Check the token, network, migrations and webhook configuration.');
            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return self::SUCCESS;
    }
}
