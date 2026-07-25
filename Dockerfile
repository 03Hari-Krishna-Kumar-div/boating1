FROM php:8.2-cli

# ─── System dependencies ─────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl zip unzip libpng-dev libjpeg-dev libfreetype6-dev \
    libonig-dev libxml2-dev libpq-dev libzip-dev \
    build-essential ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ─── Composer ─────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ─── Node.js 20 LTS (for Vite frontend build) ────────────────────────────────
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm

# ─── Working directory ────────────────────────────────────────────────────────
WORKDIR /var/www/html

# ─── Copy project files ──────────────────────────────────────────────────────
COPY . .

# ─── Install PHP dependencies (no dev, optimize autoloader) ──────────────────
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ─── Install JS dependencies and build frontend assets ───────────────────────
RUN npm install && npm run build

# ─── Laravel storage directories ──────────────────────────────────────────────
RUN mkdir -p storage/app/public storage/framework/{cache/data,sessions,testing,views} \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# ─── Make start script executable ─────────────────────────────────────────────
RUN chmod +x start.sh

# ─── Expose port ─────────────────────────────────────────────────────────────
EXPOSE 8000

# ─── Health check endpoint ───────────────────────────────────────────────────
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
    CMD curl -f http://localhost:8000/up || exit 1

# ─── Start the application (migrate + seed + cache + serve) ──────────────────
CMD ["./start.sh"]
