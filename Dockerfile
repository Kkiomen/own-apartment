# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# build — PHP + Node in one stage on purpose.
# The Wayfinder Vite plugin shells out to `php artisan wayfinder:generate`
# during `npm run build`, so the asset build needs a working PHP + vendor/
# + a dumped autoloader. Splitting node into its own stage breaks the build.
# ---------------------------------------------------------------------------
# NOTE: 8.4, not 8.3 — composer.lock resolved Symfony 8 components that
# require php >= 8.4, so `composer install` hard-fails on 8.3.
FROM php:8.4-cli-alpine AS build

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions pdo_pgsql intl zip bcmath \
    && apk add --no-cache nodejs npm

WORKDIR /app

# Dependencies first so edits to app code don't invalidate the install layers.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && npm run build \
    && rm -rf node_modules

# ---------------------------------------------------------------------------
# app — php-fpm runtime. Also used for the queue worker and the scheduler,
# they only differ by CONTAINER_ROLE + command.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS app

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# pcntl lets queue:work handle SIGTERM, so `docker compose down` finishes the
# in-flight job instead of killing it mid-scrape.
RUN install-php-extensions pdo_pgsql intl zip bcmath opcache pcntl \
    && apk add --no-cache postgresql-client

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zzz-app.ini

WORKDIR /var/www/html

COPY --from=build --chown=www-data:www-data /app .
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# web — nginx serving the built public/ and proxying PHP to the app container.
# This is the ONLY service that publishes a port to the host.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/public /var/www/html/public
