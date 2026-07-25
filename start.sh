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

# ─── Reset database schema if tables are in a broken state ─────────────────
echo "🔍 Checking database state..."
table_count=$(php artisan tinker --execute="echo count(\Illuminate\Support\Facades\DB::select('SELECT table_name FROM information_schema.tables WHERE table_schema = \'public\''));" 2>/dev/null || echo "0")
migration_status=$(php artisan migrate:status 2>/dev/null | grep -c "No" || echo "0")
if [ "$table_count" -gt 0 ] && [ "$migration_status" -gt 0 ]; then
    echo "⚠️  Database has tables but migrations incomplete — resetting schema..."
    php artisan tinker --execute="
        \Illuminate\Support\Facades\DB::statement('DROP SCHEMA public CASCADE');
        \Illuminate\Support\Facades\DB::statement('CREATE SCHEMA public');
        \Illuminate\Support\Facades\DB::statement('GRANT ALL ON SCHEMA public TO neondb_owner');
        \Illuminate\Support\Facades\DB::statement('GRANT ALL ON SCHEMA public TO public');
    " 2>/dev/null || true
    echo "   Schema reset complete."
fi

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
