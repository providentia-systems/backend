# syntax=docker/dockerfile:1.10

ARG PHP_IMAGE=php:8.5.9-cli-alpine3.23
ARG COMPOSER_IMAGE=composer:2.10.2

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM ${PHP_IMAGE}
ARG REDIS_EXTENSION_VERSION=6.3.0
ARG XDEBUG_VERSION=3.5.3

RUN apk add --no-cache bash curl ffmpeg git icu-libs jq libjpeg-turbo libpng libwebp libzip oniguruma sqlite-libs \
    && apk add --no-cache --virtual .build-deps \
        ${PHPIZE_DEPS} icu-dev libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev oniguruma-dev sqlite-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd intl mbstring pdo_mysql pdo_sqlite \
    && pecl install \
        "redis-${REDIS_EXTENSION_VERSION}" \
        "xdebug-${XDEBUG_VERSION}" \
    && docker-php-ext-enable redis xdebug \
    && apk del .build-deps

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY infrastructure/php/request-limits.ini /usr/local/etc/php/conf.d/zz-providentia-request-limits.ini

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    XDEBUG_MODE=off

WORKDIR /workspace
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

COPY . .
RUN composer dump-autoload --no-interaction --classmap-authoritative \
    && chmod +x bin/doctrine-migrations bin/providentia tool/*.sh tools/*.sh \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var /tmp/composer

USER www-data
ENTRYPOINT []
CMD ["composer", "check"]
