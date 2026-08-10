# syntax=docker/dockerfile:1.10
FROM composer:2.10.2 AS composer

FROM php:8.5.9-cli-alpine3.23 AS runtime

RUN apk add --no-cache ffmpeg icu-libs libzip oniguruma sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev sqlite-dev \
    && docker-php-ext-install -j"$(nproc)" intl mbstring pdo_mysql pdo_sqlite \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && chmod +x bin/doctrine-migrations bin/providentia infrastructure/compose/entrypoint.sh tool/*.sh \
    && mkdir -p var \
    && chown -R www-data:www-data var

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["php", "-r", "exit(@fsockopen('127.0.0.1', 8080) === false ? 1 : 0);"]
ENTRYPOINT ["/app/infrastructure/compose/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
