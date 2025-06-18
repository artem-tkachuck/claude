FROM php:8.2-fpm-alpine

# Install dependencies
RUN apk add --no-cache \
    git \
    zip \
    unzip \
    curl \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    gmp-dev \
    libsodium-dev \
    postgresql-dev \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        zip \
        intl \
        mbstring \
        opcache \
        gmp \
        bcmath \
        sodium \
        sockets

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Security: Create non-root user
RUN addgroup -g 1000 -S www && \
    adduser -u 1000 -S www -G www

# Copy PHP configuration
COPY php.ini /usr/local/etc/php/php.ini
COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY security.ini /usr/local/etc/php/conf.d/security.ini

# Set working directory
WORKDIR /var/www

# Change ownership
RUN chown -R www:www /var/www

# Switch to non-root user
USER www

# Expose port
EXPOSE 9000

CMD ["php-fpm"]