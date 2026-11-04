#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    export APP_KEY
    echo "[entrypoint] APP_KEY was not provided - generated a temporary one for this container."
fi

# Laravel's own tables (users, cache, jobs, ...). The employees dataset itself
# is imported by the database container on its first start.
php artisan migrate --force --no-interaction

php artisan view:cache >/dev/null

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
