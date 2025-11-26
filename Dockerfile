# Dockerfile for Nextcloud Time Bank App
# This provides a complete Nextcloud environment with the Time Bank app

FROM nextcloud:29-apache

# Install additional PHP extensions
RUN apt-get update && apt-get install -y \
    libmagickwand-dev \
    libzip-dev \
    libicu-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libgmp-dev \
    ffmpeg \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        gd \
        zip \
        intl \
        bcmath \
        gmp \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*

# Set recommended PHP settings for Nextcloud
RUN { \
    echo 'memory_limit=512M'; \
    echo 'upload_max_filesize=512M'; \
    echo 'post_max_size=512M'; \
    echo 'max_execution_time=3600'; \
    echo 'max_input_time=3600'; \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.revalidate_freq=1'; \
    echo 'opcache.save_comments=1'; \
} > /usr/local/etc/php/conf.d/nextcloud.ini

# Install Node.js and npm for building frontend assets
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm@latest \
    && rm -rf /var/lib/apt/lists/*

# Create directory for custom apps
RUN mkdir -p /var/www/html/custom_apps && \
    chown -R www-data:www-data /var/www/html/custom_apps

# Set volume for persistent data
VOLUME /var/www/html

EXPOSE 80

# Use the default Nextcloud entrypoint
CMD ["apache2-foreground"]
