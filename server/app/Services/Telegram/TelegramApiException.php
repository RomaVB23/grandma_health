<?php

namespace App\Services\Telegram;

use RuntimeException;

class TelegramApiException extends RuntimeException
{
    public function __construct(public readonly int $apiCode, public readonly int $retryAfter = 30)
    {
        // Never include a URL, response body or previous HTTP exception (token is in URL).
        parent::__construct('Telegram request failed (code '.$apiCode.').');
    }
}
