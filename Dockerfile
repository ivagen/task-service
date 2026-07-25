# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# base — PHP runtime shared by every stage. Versions are pinned so a rebuild
# without a repository change produces the same image.
# ---------------------------------------------------------------------------
FROM php:8.5.8-fpm-bookworm@sha256:83c155135b9c4aa664fc6ce47020a10fe53576a0ed3468119cf2efec22fd16b9 AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    zip \
    unzip \
    libfcgi-bin \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10.2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/bin/composer

COPY docker/php/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

EXPOSE 9000
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# vendor — production dependencies only, cached on composer.json/lock alone.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY www/composer.json www/composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
    && composer clear-cache

# ---------------------------------------------------------------------------
# prod — application code + production vendor, running unprivileged.
# ---------------------------------------------------------------------------
FROM base AS prod

COPY www/ .
COPY --from=vendor /var/www/vendor ./vendor

# Drop dev-only files before generating the classmap, so nothing test-related
# can end up in the production autoloader.
RUN rm -rf /var/www/tests /var/www/.php-cs-fixer.dist.php /var/www/phpunit.xml /var/www/phpstan.neon \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p /var/www/runtime /var/www/web/assets \
    && chown -R www-data:www-data /var/www/runtime /var/www/web/assets

USER www-data

# ---------------------------------------------------------------------------
# dev — full dependency set including PHPUnit and PHP CS Fixer.
# ---------------------------------------------------------------------------
FROM base AS dev

COPY www/ .
RUN composer install --no-interaction --no-scripts --prefer-dist \
    && mkdir -p /var/www/runtime /var/www/web/assets \
    && chown -R www-data:www-data /var/www/runtime /var/www/web/assets
