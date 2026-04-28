FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache bash git icu-dev libzip-dev oniguruma-dev mysql-client $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql intl zip pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

COPY backend/ ./
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
