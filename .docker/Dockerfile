FROM composer:2.9.5 AS composer_base

WORKDIR /app

# Copy only composer files to leverage Docker cache
COPY composer.json composer.lock symfony.lock ./


FROM composer_base AS composer_dev

RUN composer install --prefer-dist --no-scripts --no-progress --no-interaction


FROM composer_base AS composer_prod

RUN composer install --prefer-dist --no-scripts --no-progress --no-interaction --no-dev

# --- 2. PHP Base (Apache + Extensions) ---
FROM php:8.4-apache AS php_base

WORKDIR /var/www/app-google/

# Install system dependencies
RUN apt update && apt upgrade -y \
    && docker-php-ext-install pdo pdo_mysql \
    && apt clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Configure Apache
COPY .docker/apache-config.conf /etc/apache2/sites-available/000-default.conf



FROM php_base AS development

RUN pecl install xdebug && docker-php-ext-enable xdebug

ENV APP_ENV=dev
ENV APACHE_SERVER_NAME=app-google.localhost

COPY --from=composer_dev /app/vendor /var/www/app-google/vendor
COPY --from=composer_dev /app/composer.json /app/composer.lock /app/symfony.lock /var/www/app-google/

# --- 4. PHP Production Stage ---
FROM php_base AS production

ENV APP_ENV=prod
ENV APACHE_SERVER_NAME=app-google.dlyhub.com

# Copy source code
COPY . .

#COPY --from=composer_deps /usr/bin/composer /usr/bin/composer
COPY --from=composer_prod /app/vendor /var/www/app-google/vendor
COPY --from=composer_prod /app/composer.json /app/composer.lock /app/symfony.lock /var/www/app-google/

# Set permissions
RUN mkdir -p var/cache/

RUN chown -R www-data:www-data /var/www/back/var
