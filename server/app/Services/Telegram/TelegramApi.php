<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramApi
{
    public function call(string $method, array $payload = []): mixed
    {
        try {
            $response = Http::connectTimeout(5)->timeout(30)->withoutRedirecting()
                ->post('https://api.telegram.org/bot'.config('telegram.token').'/'.$method, $payload);
        } catch (Throwable) {
            throw new TelegramApiException(0);
        }
        return $this->result($response);
    }

    public function photo(array $payload, string $png): mixed
    {
        try {
            $response = Http::connectTimeout(5)->timeout(30)->withoutRedirecting()
                ->attach('photo', $png, 'pulse.png', ['Content-Type' => 'image/png'])
                ->post('https://api.telegram.org/bot'.config('telegram.token').'/sendPhoto', $payload);
        } catch (Throwable) {
            throw new TelegramApiException(0);
        }
        return $this->result($response);
    }

    private function result(\Illuminate\Http\Client\Response $response): mixed
    {
        $data = $response->json();
        if (!$response->successful() || !is_array($data) || ($data['ok'] ?? false) !== true) {
            $code = (int) ($data['error_code'] ?? $response->status());
            throw new TelegramApiException($code, max(1, min(3600, (int) ($data['parameters']['retry_after'] ?? 30))));
        }

        return $data['result'] ?? null;
    }
}
