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

# Initial administrator (ADMIN_EMAIL / ADMIN_PASSWORD) and demo accounts; existing
# accounts are never changed, so this is safe on every start.
php artisan db:seed --class=UserSeeder --force --no-interaction

php artisan view:cache >/dev/null

chown -R www-data:www-data storage bootstrap/cache

# First start: compute the department and report figures in the background, as Apache's
# user, so the first visitors find them cached. The app does not wait for this. The mark
# lives on the cache volume; the scheduler keeps the figures warm from then on.
WARMED_MARK=storage/framework/cache/.figures-warmed
if [ ! -f "$WARMED_MARK" ]; then
    su -s /bin/sh www-data -c "php artisan departments:warm && php artisan reports:warm && touch $WARMED_MARK" &
fi

exec "$@"
