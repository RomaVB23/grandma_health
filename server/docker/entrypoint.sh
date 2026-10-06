#!/bin/sh
set -eu

php -r 'if (!getenv("APP_KEY") || strlen(getenv("TELEMETRY_TOKEN") ?: "") < 32) { fwrite(STDERR, "APP_KEY and TELEMETRY_TOKEN must be configured.\n"); exit(1); }'
mkdir -p /app/data /app/storage/framework/cache/data /app/storage/framework/sessions \
    /app/storage/framework/views /app/storage/logs /app/bootstrap/cache
test -f /app/data/health.sqlite || touch /app/data/health.sqlite
php artisan config:clear
php artisan migrate --force --no-interaction

exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
