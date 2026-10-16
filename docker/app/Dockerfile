# syntax=docker/dockerfile:1.7
# One image for app (FrankenPHP, classic mode), queue (Horizon) and scheduler.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY packages ./packages
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
COPY packages ./packages
COPY resources ./resources
# form-schema-js imports the normative spec JSON (meta-schema, roles) at build time.
COPY spec ./spec
RUN npm ci --ignore-scripts || npm install --ignore-scripts
COPY vite.config.js ./
COPY public ./public
RUN npm run build

FROM dunglas/frankenphp:1-php8.4-bookworm AS app
RUN install-php-extensions pdo_pgsql intl gd zip bcmath exif pcntl opcache redis \
 && apt-get update \
 && apt-get install -y --no-install-recommends qpdf curl \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
ENV SERVER_NAME=:8080 \
    APP_ENV=production \
    LOG_CHANNEL=stderr \
    LOG_STDERR_FORMATTER=Monolog\\Formatter\\JsonFormatter
COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY --from=assets /app/public/app ./public/app
COPY --from=assets /app/public/form-preview ./public/form-preview
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
 && php artisan filament:assets \
 && chown -R www-data:www-data storage bootstrap/cache
COPY docker/app/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint
USER www-data
EXPOSE 8080
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
