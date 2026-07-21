#!/bin/sh
set -e
cd /var/www/html

# Setup (migrasi + cache) HANYA dijalankan kontainer web (php-fpm). Worker,
# reverb, dan scheduler memakai image yang sama tetapi langsung exec perintahnya
# — biar migrasi tidak balapan dijalankan banyak kontainer sekaligus.
if [ "$1" = "php-fpm" ]; then
    echo "[entrypoint] menunggu database siap..."
    until php artisan migrate:status >/dev/null 2>&1; do
        sleep 2
    done

    echo "[entrypoint] migrasi + optimasi..."
    php artisan migrate --force
    php artisan storage:link 2>/dev/null || true
    php artisan optimize
    php artisan filament:optimize

    # Seed sekali saja (aman diulang: seeder pakai create/updateOrCreate).
    if [ "${APP_SEED_ON_BOOT:-false}" = "true" ]; then
        php artisan db:seed --force || true
    fi

    chown -R www-data:www-data storage bootstrap/cache
    echo "[entrypoint] siap."
fi

exec "$@"
