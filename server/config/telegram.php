<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN', ''),
    'owner_id' => env('TELEGRAM_OWNER_ID', ''),
    'username' => '', // Verified with getMe at worker startup.
    'timezone' => env('TELEGRAM_TIMEZONE', 'Europe/Minsk'),
    'charts_enabled' => env('TELEGRAM_CHARTS_ENABLED', true),
    'battery_low_percent' => 20,
    'battery_recovered_percent' => 25,
    'battery_fresh_ms' => 600_000,
    'invite_ttl_ms' => 86_400_000,
];
