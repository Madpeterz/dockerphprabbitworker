# Install Composer and make vendor
# Use the official Composer image as the first stage
FROM composer:1.9.3 AS composer

# Use the official PHP image as the second stage
FROM php:8.2.12

# Copy the Composer PHAR from the Composer image into the PHP image
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Verify that both Composer and PHP are working
RUN composer --version && php -v

WORKDIR /app/
COPY composer.* ./

RUN composer install \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --no-dev

COPY --from=composer /app/vendor /var/www/vendor

ENV RABBITMQ_HOST='' \
    RABBITMQ_PORT=5672 \
    RABBITMQ_USER='guest' \
    RABBITMQ_PASSWORD='guest' \
    RABBITMQ_QUEUE='default_queue' \
    RABBITMQ_VHOST='/' \
    ENABLE_ECHO_OUTPUT=false \
    USE_SECOND_LIFE_BATCHING=false \
    RECOVERY_WAIT_TIME=30

# Setup entry points
RUN apt-get update \
    && apt-get clean    

# swap cmd to the worker
CMD ["php", "-d", "/app/src/worker.php"]