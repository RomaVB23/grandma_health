<?php

return [
    'token' => env('TELEMETRY_TOKEN', ''),
    'device_id' => env('TELEMETRY_DEVICE_ID', 'grandma-watch'),
    // Technical freshness limits, not medical alarm thresholds.
    'clock_tolerance_ms' => 120_000,
    'contact_timeout_ms' => 600_000,
    'measurement_stale_ms' => 300_000,
];
