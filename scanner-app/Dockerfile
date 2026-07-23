FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    curl \
    git \
    && docker-php-ext-install pdo pdo_pgsql

# Install Node.js
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# We will mount the directory here in development, but for now just keep the container alive
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8002"]
