#!/bin/bash
set -e

echo "🚀 Starting Dhanalakshmi Boating application..."

# ─── Wait for database to be ready ──────────────────────────────────────────
echo "⏳ Waiting for database connection..."
max_attempts=30
attempt=0
until php artisan migrate:status >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "⚠️  Database not reachable after $max_attempts attempts. Continuing anyway..."
        break
    fi
    echo "   Attempt $attempt/$max_attempts - waiting 2s..."
    sleep 2
done

# ─── Run migrations ─────────────────────────────────────────────────────────
echo "🔄 Running database migrations..."
php artisan migrate --force

# ─── Seed database (only if users table is empty) ───────────────────────────
echo "🌱 Checking if database needs seeding..."
user_count=$(php artisan tinker --execute="echo App\Models\User::count();" 2>/dev/null || echo "0")
if [ "$user_count" = "0" ]; then
    echo "   Seeding database with default data..."
    php artisan db:seed --force
else
    echo "   Database already has $user_count users. Skipping seed."
fi

# ─── Create storage link ────────────────────────────────────────────────────
php artisan storage:link --force 2>/dev/null || true

# ─── Clear and rebuild caches ───────────────────────────────────────────────
echo "📦 Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ─── Start the server ──────────────────────────────────────────────────────
echo "✅ Application ready! Starting server on port ${PORT:-8000}..."
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
