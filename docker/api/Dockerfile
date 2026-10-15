FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libonig-dev libxml2-dev libicu-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_mysql mbstring zip intl bcmath gd pcntl exif \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY entrypoint.sh /usr/local/bin/mediahub-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/mediahub-entrypoint && chmod +x /usr/local/bin/mediahub-entrypoint
ENTRYPOINT ["mediahub-entrypoint"]
