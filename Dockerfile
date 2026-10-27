# Reservas (Laravel 12, PHP 8.3 sobre FrankenPHP). Construir: docker build -t condohub-reservas services/reservas
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --ignore-platform-reqs

FROM dunglas/frankenphp:1-php8.3
WORKDIR /app
RUN install-php-extensions pdo_pgsql opcache sockets
COPY --from=vendor /app /app
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && mkdir -p /data/caddy /config/caddy \
 && chown -R www-data:www-data storage bootstrap/cache /data /config
USER www-data
EXPOSE 8082
# Obligatorios por entorno: APP_KEY, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, KEYCLOAK_ISSUER
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr CACHE_STORE=file SESSION_DRIVER=array QUEUE_CONNECTION=sync
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=5 \
  CMD php -r "exit(@fsockopen('127.0.0.1', 8082) ? 0 : 1);"
ENTRYPOINT ["entrypoint.sh"]