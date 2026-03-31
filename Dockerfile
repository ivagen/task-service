FROM php:8.5-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY www/ .

RUN composer install --no-interaction --no-scripts --prefer-dist

RUN mkdir -p /var/www/runtime /var/www/web/assets \
    && chown -R www-data:www-data /var/www/runtime /var/www/web/assets

EXPOSE 9000
CMD ["php-fpm"]