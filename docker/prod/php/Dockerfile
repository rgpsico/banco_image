# Imagem de PRODUÇÃO da API: PHP-FPM com OPcache, sem dependências de desenvolvimento, código dentro da imagem.
FROM php:8.4-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg fonts-dejavu-core git unzip libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libonig-dev libxml2-dev libicu-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_mysql mbstring zip intl bcmath gd pcntl exif opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www

# ---- dependências (camada separada: só refaz se composer.json/lock mudarem) ----
FROM base AS vendor
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY api/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

# ---- imagem final ----
FROM base AS app
COPY docker/prod/php/php.ini /usr/local/etc/php/conf.d/zz-mediahub.ini
COPY docker/prod/php/pool.conf /usr/local/etc/php-fpm.d/zz-mediahub.conf
COPY --from=vendor /var/www /var/www
COPY docker/prod/php/entrypoint.sh /usr/local/bin/mediahub-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/mediahub-entrypoint && chmod +x /usr/local/bin/mediahub-entrypoint \
    && rm -f /var/www/.env && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

ENTRYPOINT ["mediahub-entrypoint"]
CMD ["php-fpm"]
