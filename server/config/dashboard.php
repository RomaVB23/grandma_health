<?php

return [
    'password_file' => base_path('data/dashboard-password.hash'),
    'timezone' => env('DASHBOARD_TIMEZONE', env('TELEGRAM_TIMEZONE', 'Europe/Minsk')),
    'idle_timeout_seconds' => 1800,
];
