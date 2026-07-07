FROM php:8.1-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
        curl \
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
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY php.ini /usr/local/etc/php/conf.d/io200.ini

RUN set -eux; \
    curl -o /tmp/dist.zip "https://www.service.io200.com/api/v1/download:distribution?install"; \
    unzip -o /tmp/dist.zip -d /tmp/dist; \
    cp -r /tmp/dist/system-distribution/* /var/www/html/; \
    rm -rf /tmp/dist.zip /tmp/dist

RUN set -eux; \
    chown -R www-data:www-data /var/www/html; \
    chmod -R 755 /var/www/html

RUN set -eux; \
    cp /var/www/html/storage/temp/cms_db_schema.sql /cms_db_schema.sql; \
    rm -rf /var/www/html/storage/temp

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

VOLUME /var/www/html/storage

ENTRYPOINT ["/entrypoint.sh"]
