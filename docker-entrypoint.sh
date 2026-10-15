#!/bin/bash
set -e

# Ensure storage directories exist (important when volume is first mounted)
mkdir -p /app/storage/logs \
         /app/storage/framework/cache \
         /app/storage/framework/sessions \
         /app/storage/framework/views \
         /app/storage/app/public/logos \
         /app/bootstrap/cache

# Set permissions. Touch only entries that differ: a recursive chmod/chown rewrote
# the whole file cache on the volume (116k files, ~2.5 min) on every start, and a
# volume-backed service serves no traffic until this container is up.
find /app/storage /app/bootstrap/cache ! -perm 775 -exec chmod 775 {} +
find /app/storage /app/bootstrap/cache \( ! -user www-data -o ! -group www-data \) -exec chown www-data:www-data {} +

# Create storage symlink (for public access to logos)
php artisan storage:link --force 2>/dev/null || true

# Run migrations
php artisan migrate --force

# Cache config and routes for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Warm contract listing caches for common preset consumptions.
# This must run at container start because it depends on the live database.
if [ "${WARM_CONTRACT_CACHE_ON_START:-1}" = "1" ]; then
    php artisan contracts:warm-cache || echo "Warning: contract cache warming failed, continuing startup"
fi

# Start supervisord (FrankenPHP web server, Laravel scheduler, and queue worker)
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
