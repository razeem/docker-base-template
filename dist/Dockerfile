# ------------------------------- base image -----------------------------------
# Use PHP 8.3-fpm
FROM php:8.3-fpm

# Install system dependencies and required dev libs
RUN apt-get update && apt-get install -y \
    nginx \
    cron \
    supervisor \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    unzip \
    git \
    wget \
    curl \
    nano \
    pkg-config \
    libz-dev \
    libssl-dev \
    libmemcached-dev \
    libsasl2-dev \
    openssh-server \
    && rm -rf /var/lib/apt/lists/*

# Set path for libmemcached so pecl can find it
ENV MEMCACHED_DIR=/usr

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli zip \
    && pecl install memcached \
    && docker-php-ext-enable memcached

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
# ------------------------------- base image ends ------------------------------

# Install PHP GD extension (hard-coded)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# Copy the package list file into the image
COPY ./Docker/app/apt-packages.env /tmp/apt-packages.env

# Install additional packages from the external file.
RUN apt-get update && \
    xargs -a /tmp/apt-packages.env apt-get install -y && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# ------------------------------- Files related --------------------------------
# Set working directory
WORKDIR /app

# Copy Drupal project files
COPY ./web /app/web
COPY composer.json /app/composer.json
COPY composer.lock /app/composer.lock
COPY config /app/config
# Copy patches folder only if it exists
RUN if [ -d "./patches" ]; then cp -r ./patches /app/patches; fi

# create folder for assets and php files outside files folder (default location)
RUN mkdir -p /app/web/sites/default/dist/assets/css \
    /app/web/sites/default/dist/assets/js \
    /app/web/sites/default/dist/php \
    /app/web/sites/default/files

# Set proper permissions
RUN chown -R www-data:www-data /app \
&& chmod -R 755 /app \
&& chmod -R 755 /app/web/sites/default/files \
&& chmod -R 755 /app/web/sites/default/dist
# ------------------------------- Files related ends ---------------------------

# Install Composer dependencies
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --no-interaction --no-dev --optimize-autoloader --prefer-dist --no-scripts

# Copy PHP configuration files
COPY ./Docker/php/php.ini /usr/local/etc/php/conf.d/php.ini

# SSH setup
RUN echo "root:Docker!" | chpasswd \
    && mkdir -p /var/run/sshd

COPY Docker/ssh/sshd_config /etc/ssh/sshd_config
COPY Docker/ssh/ssh_setup.sh /tmp/ssh_setup.sh

RUN chmod +x /tmp/ssh_setup.sh && \
    /tmp/ssh_setup.sh > /dev/null 2>&1     

# Nginx configuration
COPY Docker/nginx/nginx.conf /etc/nginx/sites-available/default

# Supervisor configuration
COPY Docker/app/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy SSL certificate to the application directory
COPY Docker/app/SslCertificate.crt.pem /app

# Copy Drupal settings file
COPY Docker/app/settings.php /app/web/sites/default/settings.php

# Set up cron for Drupal
COPY Docker/app/crontab /etc/cron.d/app-crontab
RUN chmod 0644 /etc/cron.d/app-crontab && crontab /etc/cron.d/app-crontab
# Copy the entrypoint
COPY Docker/scripts/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set permissions
RUN chown -R www-data:www-data /app \
    && chmod -R 755 /app

# Expose HTTP, HTTPS and SSH ports
EXPOSE 80 443 2222

# Use it as the container entrypoint
ENTRYPOINT ["/entrypoint.sh"]
