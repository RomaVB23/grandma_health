FROM dunglas/frankenphp:1-php8.4-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN install-php-extensions pdo_sqlite mbstring intl zip pcntl gd \
    && apt-get update \
    && apt-get install -y --no-install-recommends fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs \
    && composer install --no-interaction --prefer-dist --optimize-autoloader \
    && chmod +x docker/entrypoint.sh
RUN sed -i 's/\r$//' docker/entrypoint.sh
COPY docker/Caddyfile /etc/caddy/Caddyfile

EXPOSE 8080
ENTRYPOINT ["sh", "/app/docker/entrypoint.sh"]
