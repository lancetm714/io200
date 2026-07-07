FROM php:8.1-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libmagickwand-dev \
        unzip \
    ; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j$(nproc) \
        gd \
        mysqli \
        pdo \
        pdo_mysql \
        zip \
        exif \
    ; \
    pecl install imagick-3.7.0 && docker-php-ext-enable imagick || true; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY php.ini /usr/local/etc/php/conf.d/io200.ini

COPY . /var/www/html/

RUN set -eux; \
    chown -R www-data:www-data /var/www/html; \
    chmod -R 755 /var/www/html; \
    chmod 644 /var/www/html/install.php

VOLUME /var/www/html
