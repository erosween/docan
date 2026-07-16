FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --optimize-autoloader --no-scripts

FROM dunglas/frankenphp:1-php8.3-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends curl openssl \
    && install-php-extensions pdo_pgsql intl zip opcache redis \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/php-production.ini /usr/local/etc/php/conf.d/99-docan-production.ini
COPY docker/entrypoint.sh /usr/local/bin/kasir-entrypoint

RUN chmod +x /usr/local/bin/kasir-entrypoint \
    && rm -f bootstrap/cache/*.php \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:80

EXPOSE 80
ENTRYPOINT ["kasir-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
