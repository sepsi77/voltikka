#!/bin/bash
set -e

# Ensure storage directories exist (important when volume is first mounted)
mkdir -p /app/storage/logs \
         /app/storage/framework/cache \
         /app/storage/framework/sessions \
         /app/storage/framework/views \
         /app/storage/app/public/logos \
         /app/bootstrap/cache

# Set permissions. A volume-backed service serves no traffic until this container is
# up, so keep this fast: touch only entries that differ, and skip the file-cache
# contents (116k files; even reading their metadata took ~70 s). Every process runs
# as root, so cache file ownership has no effect.
CACHE_DIR=/app/storage/framework/cache
find /app/storage /app/bootstrap/cache -path "$CACHE_DIR" -prune -o ! -perm 775 -exec chmod 775 {} +
find /app/storage /app/bootstrap/cache -path "$CACHE_DIR" -prune -o \( ! -user www-data -o ! -group www-data \) -exec chown www-data:www-data {} +
chmod 775 "$CACHE_DIR"
chown www-data:www-data "$CACHE_DIR"

# Create storage symlink (for public access to logos)
php artisan storage:link --force 2>/dev/null || true

# After a server reboot, Docker starts this container and the MySQL and Redis accessories at the
# same time (VPS, 2026-11-16: migrate got "connection refused", and Docker restarted the container).
# Wait up to 60 s for each host that the environment names; a missing host is not checked.
wait_for_tcp() {
    [ -n "$1" ] || return 0
    for _ in $(seq 1 60); do
        php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $n, $m, 1) ? 0 : 1);' "$1" "$2" && return 0
        sleep 1
    done
    echo "Warning: $1:$2 did not answer in 60 s, continuing startup"
}
wait_for_tcp "${DB_HOST:-}" "${DB_PORT:-3306}"
wait_for_tcp "${REDIS_HOST:-}" "${REDIS_PORT:-6379}"

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
