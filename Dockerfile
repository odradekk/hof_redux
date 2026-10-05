FROM php:8.4.26-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl mbstring bcmath opcache zip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/hof.ini
FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY app app
COPY bootstrap bootstrap
COPY config config
COPY content content
COPY database database
COPY public public
COPY resources resources
COPY routes routes
COPY artisan ./
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction
FROM base AS app
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
USER www-data
CMD ["php-fpm"]
FROM nginx:1.28-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /var/www/html/public /var/www/html/public
