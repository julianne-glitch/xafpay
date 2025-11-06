FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    unzip curl git libpq-dev \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql

# Set working directory
WORKDIR /app
COPY . /app

# Install composer dependencies
RUN composer install --no-interaction --no-progress || true

# Expose the port Render uses
EXPOSE 8000

# Start command — use PHP built-in server and your router
CMD ["php", "-S", "0.0.0.0:8000", "server.php"]
