FROM php:8.3-cli

# System dependencies + PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl zip unzip libpng-dev libjpeg-dev libfreetype6-dev \
    libonig-dev libxml2-dev libpq-dev libzip-dev \
    build-essential ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node.js 20 LTS
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

RUN npm install && npm run build

RUN mkdir -p storage/app/public storage/framework/{cache/data,sessions,testing,views} \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN chmod +x start.sh

EXPOSE 8000

CMD ["./start.sh"]
