# syntax=docker/dockerfile:1

# --- dependências PHP (sem dev) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# --- imagem final: PHP 8.4 + Nginx (serversideup), com pdo_pgsql ---
FROM serversideup/php:8.4-fpm-nginx AS app

ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_STORAGE_LINK=false \
    SSL_MODE=off

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app ./

# O Laravel precisa escrever aqui; documentos ficam em storage/app (monte um volume persistente).
RUN mkdir -p storage/app/private storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
