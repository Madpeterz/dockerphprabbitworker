FROM madpeter/phpapachepreload:php82

MAINTAINER Madpeter

COPY --chown=www-data:www-data . /srv/website
COPY .docker/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /srv/website

ENV RABBITMQ_HOST='' \
    RABBITMQ_PORT=5672 \
    RABBITMQ_USER='guest' \
    RABBITMQ_PASSWORD='guest' \
    RABBITMQ_QUEUE='default_queue' \
    RABBITMQ_VHOST='/' \
    ENABLE_ECHO_OUTPUT=false \
    USE_SECOND_LIFE_BATCHING=false \
    RECOVERY_WAIT_TIME=30

# Install Composer and make vendor
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN composer install \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --no-dev

# Setup entry points
RUN apt-get update \
    && apt-get clean    

# swap cmd to the worker
CMD ["php", "-d", "src/worker.php"]