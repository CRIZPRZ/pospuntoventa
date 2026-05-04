FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git \
    libzip-dev \
    libjpeg-dev \
    libpng-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd zip pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install redis && docker-php-ext-enable redis

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
RUN apt-get install -y nodejs

WORKDIR /var/www/ventas

EXPOSE 9000

ENV COMPOSER_PROCESS_TIMEOUT=600

CMD ["php-fpm"]
