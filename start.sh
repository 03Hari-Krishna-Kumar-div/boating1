#!/bin/bash

echo "========================================="
echo "  Dhanalakshmi Boating - Starting up"
echo "========================================="

# Wait for database to be ready (Neon free tier needs time to wake up from sleep)
echo ""
echo "[1/6] Waiting for database connection..."
max_attempts=90
attempt=0
until php artisan migrate:status >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "   WARNING: Database not reachable after $max_attempts attempts (4.5 min)."
        echo "   Continuing anyway and will attempt migration..."
        break
    fi
    if [ $((attempt % 10)) -eq 0 ]; then
        echo "   Attempt $attempt/$max_attempts - still waiting..."
    fi
    sleep 3
done

if [ "$attempt" -lt "$max_attempts" ]; then
    echo "   Database connected after $attempt attempts."
fi

# Run migrations
echo ""
echo "[2/6] Running database migrations..."
php artisan migrate --force 2>&1 | head -30

# Seed database (only if users table is empty)
echo ""
echo "[3/6] Checking if database needs seeding..."
user_count=$(php artisan tinker --execute="echo App\Models\User::count();" 2>/dev/null || echo "error")
if [ "$user_count" = "error" ]; then
    echo "   WARNING: Could not check user count. Attempting seed anyway..."
    php artisan db:seed --force 2>&1 || echo "   Seed skipped (may already have data)."
elif [ "$user_count" = "0" ]; then
    echo "   Database has no users. Seeding..."
    php artisan db:seed --force 2>&1
    echo "   Seeding complete."
else
    echo "   Database already has $user_count users. Skipping seed."
fi

# Create storage link
echo ""
echo "[4/6] Creating storage link..."
php artisan storage:link --force 2>/dev/null || true

# Clear and rebuild caches
echo ""
echo "[5/6] Caching configuration and routes..."

# Fix APP_URL: force https if it starts with http:// (Render reverse proxy issue)
if [ -n "$APP_URL" ] && [[ "$APP_URL" == http://* ]]; then
    FIXED_URL="${APP_URL/http:\/\//https:\/\/}"
    export APP_URL="$FIXED_URL"
    echo "   Fixed APP_URL: $APP_URL (forced HTTPS)"
fi

# Fix session settings for mobile browser compatibility
# SESSION_DOMAIN must be empty/null — mobile browsers drop cookies with parent domain
export SESSION_DOMAIN=""
export SESSION_SECURE_COOKIE=true
export SESSION_SAME_SITE=lax

php artisan config:clear 2>&1 || true
php artisan config:cache 2>&1
php artisan route:cache 2>&1
php artisan view:cache 2>&1

# Verify DB connection with a quick test
echo ""
echo "[6/6] Verifying database..."
php artisan tinker --execute="
try {
    \$boats = \App\Models\Boat::count();
    \$users = \App\Models\User::count();
    echo \"DB OK: \$users users, \$boats boats\";
} catch (Exception \$e) {
    echo 'DB ERROR: ' . \$e->getMessage();
}
" 2>/dev/null || echo "   Could not verify DB."

echo ""
echo "========================================="
echo "  Application ready!"
echo "  Starting server on port ${PORT:-8000}"
echo "========================================="
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
