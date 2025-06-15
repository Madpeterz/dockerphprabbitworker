# Use the official PHP image as the second stage
FROM php:8.2.12

# Install necessary packages / Install PHP extensions which depend on external libraries
RUN \
    apt-get update \
    && apt-get install -y openssl \
    && apt-get install -y libzip-dev \
    && apt-get install -y unzip \
    && apt-get update \
    && apt-get install -y --no-install-recommends libssl-dev libcurl4-openssl-dev \
    && docker-php-ext-configure curl --with-curl \
    && docker-php-ext-install curl \
    && docker-php-ext-install zip \
    && docker-php-ext-install sockets \
    && apt-get update \
    && apt-get clean

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader

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
CMD ["php", "src/worker.php"]