FROM php:8.3-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql

WORKDIR /var/www/html
COPY . /var/www/html

RUN apt-get update && apt-get install -y unzip curl git \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN composer install --no-interaction --no-progress || true

EXPOSE 8080

CMD sed -i "s/80/\${PORT}/g" /etc/apache2/ports.conf && apache2-foreground
