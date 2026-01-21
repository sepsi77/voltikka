#!/bin/bash
set -e

# Ensure storage directories exist (important when volume is first mounted)
mkdir -p /app/storage/logs \
         /app/storage/framework/cache \
         /app/storage/framework/sessions \
         /app/storage/framework/views \
         /app/storage/app/public/logos \
         /app/bootstrap/cache

# Set permissions
chmod -R 775 /app/storage /app/bootstrap/cache
chown -R www-data:www-data /app/storage /app/bootstrap/cache

# Create storage symlink (for public access to logos)
php artisan storage:link --force 2>/dev/null || true

# Run migrations
php artisan migrate --force

# Cache config and routes for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
