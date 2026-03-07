FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    supervisor \
    && docker-php-ext-install mysqli zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/micrologs

# Copy full project and install dependencies
COPY . .
RUN composer install --no-interaction --no-dev --optimize-autoloader

# PHP-FPM pool config — increase workers for load testing
RUN sed -i 's/pm.max_children = 5/pm.max_children = 50/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm.start_servers = 2/pm.start_servers = 10/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm.min_spare_servers = 1/pm.min_spare_servers = 5/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/pm.max_spare_servers = 3/pm.max_spare_servers = 20/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/listen = 127.0.0.1:9000/listen = 0.0.0.0:9000/' /usr/local/etc/php-fpm.d/www.conf

# PHP config — increase connection limits for load testing
RUN echo "mysqli.max_connections = 500" >> /usr/local/etc/php/conf.d/micrologs.ini \
    && echo "max_execution_time = 30" >> /usr/local/etc/php/conf.d/micrologs.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/micrologs.ini

EXPOSE 9000