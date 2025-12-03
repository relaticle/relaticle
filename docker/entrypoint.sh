#!/bin/sh
set -e

# Create log directories
mkdir -p /var/log/php /var/log/nginx /var/log/supervisor
chown -R www-data:www-data /var/log/php

# Ensure storage and cache directories exist with correct permissions
cd /var/www/html

# Create storage directories if they don't exist
mkdir -p storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Set permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Wait for database to be ready (if DATABASE_URL or DB_HOST is set)
if [ -n "$DB_HOST" ]; then
    echo "Waiting for database connection..."
    max_tries=30
    tries=0
    while [ $tries -lt $max_tries ]; do
        if php artisan db:monitor > /dev/null 2>&1; then
            echo "Database is ready!"
            break
        fi
        tries=$((tries + 1))
        echo "Database not ready, attempt $tries/$max_tries..."
        sleep 2
    done
    if [ $tries -eq $max_tries ]; then
        echo "Warning: Could not connect to database after $max_tries attempts"
    fi
fi

# Run migrations if AUTO_MIGRATE is set
if [ "$AUTO_MIGRATE" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force --no-interaction
fi

# Cache configuration for production
if [ "$APP_ENV" = "production" ]; then
    echo "Caching configuration for production..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    php artisan icons:cache
fi

# Create storage link if it doesn't exist
if [ ! -L "public/storage" ]; then
    echo "Creating storage symlink..."
    php artisan storage:link
fi

# Execute the main command
exec "$@"
