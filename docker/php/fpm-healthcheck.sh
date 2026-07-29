#!/bin/sh
# Healthcheck PHP-FPM via cgi-fcgi (paket fcgi terpasang di image).
set -e
SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 >/dev/null 2>&1
