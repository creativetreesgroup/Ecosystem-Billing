# syntax=docker/dockerfile:1
#
# Image aplikasi Creative Trees Billing (PHP-FPM). Satu image dipakai untuk
# SEMUA peran: web (php-fpm), reverb (websocket), worker (queue), scheduler.
# Peran ditentukan oleh `command` di docker-compose, bukan image berbeda.

# PHP 8.5: cocok dengan mesin dev/produksi (CLAUDE.md) DAN memenuhi lantai
# composer.lock (Symfony 8.1 butuh php >=8.4.1). Jangan turunkan ke 8.3 —
# build composer akan gagal karena lock menuntut >=8.4.1.
FROM php:8.5-fpm-alpine AS app

# Ekstensi PHP native lewat installer resmi (menangani dependensi sistemnya).
# gd = render QR/kartu ke TV; intl/bcmath = uang & lokalisasi; pcntl = sinyal
# reverb/queue; opcache = performa; pdo_mysql/zip/exif = DB, arsip, validasi gambar.
# sockets = socket_create() untuk magic packet Wake-on-LAN; tanpa ini
# menyalakan PS5 gagal DI PRODUKSI dengan "undefined function", bukan sekadar
# di test — unit tidak pernah menyala dan penyebabnya tak terlihat dari panel.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_mysql mbstring bcmath gd intl zip pcntl opcache exif sockets \
    && apk add --no-cache fcgi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Konfigurasi PHP produksi (opcache + batas upload bukti transfer).
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Dependensi dulu (layer cache): berubah hanya bila composer.json/lock berubah.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# Kode aplikasi.
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache

# Healthcheck FPM (dipakai compose untuk tahu web siap).
COPY docker/php/fpm-healthcheck.sh /usr/local/bin/fpm-healthcheck
RUN chmod +x /usr/local/bin/fpm-healthcheck

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
