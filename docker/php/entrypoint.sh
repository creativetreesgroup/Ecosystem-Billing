#!/bin/sh
set -e
cd /var/www/html

# Setup (migrasi + cache) HANYA dijalankan kontainer web (php-fpm). Worker,
# reverb, dan scheduler memakai image yang sama tetapi langsung exec perintahnya
# — biar migrasi tidak balapan dijalankan banyak kontainer sekaligus.
if [ "$1" = "php-fpm" ]; then
    echo "[entrypoint] menunggu database siap..."
    # Cek KETERSAMBUNGAN DB, bukan status migrasi: pada DB baru tabel migrations
    # belum ada, jadi `migrate:status` selalu exit 1 dan loop-nya deadlock. PDO
    # ping berhasil begitu server menerima koneksi, terlepas dari ada/tidaknya tabel.
    until php -r '
        new PDO(
            "mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: "3306").";dbname=".getenv("DB_DATABASE"),
            getenv("DB_USERNAME"),
            getenv("DB_PASSWORD")
        );
    ' >/dev/null 2>&1; do
        sleep 2
    done

    echo "[entrypoint] migrasi + optimasi..."
    php artisan migrate --force
    php artisan storage:link 2>/dev/null || true
    php artisan optimize
    php artisan filament:optimize

    # Sengaja TIDAK menjalankan db:seed: seeder proyek ini dev-only (butuh
    # fakerphp/faker yang di-exclude --no-dev, dan membuat data contoh +
    # user @creativetrees.test yang tak boleh masuk DB outlet sungguhan).
    # Aplikasi berjalan penuh tanpa seed — setting jatuh ke nilai bawaan enum.
    # Owner + outlet default dibuat manual: `php artisan app:create-owner`
    # (BUKAN make:filament-user — role & outlet_id wajib terisi).

    chown -R www-data:www-data storage bootstrap/cache
    echo "[entrypoint] siap."
fi

exec "$@"
