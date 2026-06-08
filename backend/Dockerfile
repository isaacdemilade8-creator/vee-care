FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y git unzip libzip-dev libpng-dev default-mysql-client \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

COPY . .
