#!/usr/bin/env bash
set -euo pipefail

# Restore satu file backup .sql.gz ke database. WAJIB dites minimal sekali
# saat setup awal dan tiap kali proses backup berubah — restore yang belum
# pernah dicoba bukan backup, cuma harapan (§11, lihat catatan uji restore
# di RUNBOOK.md).
#
# Pemakaian: deploy/backup/restore-database.sh /path/to/backup.sql.gz
# APP_DIR bisa dioverride lewat environment variable (dipakai untuk uji
# restore lokal); default cocok untuk instalasi production standar.

APP_DIR="${APP_DIR:-/var/www/creative-trees-billing}"
BACKUP_FILE="${1:?Usage: restore-database.sh <path-to-backup.sql.gz>}"

set -a
# shellcheck disable=SC1091
source "${APP_DIR}/.env"
set +a

CREDENTIALS_FILE="$(mktemp)"
trap 'rm -f "${CREDENTIALS_FILE}"' EXIT
cat > "${CREDENTIALS_FILE}" <<EOF
[client]
host=${DB_HOST}
port=${DB_PORT}
user=${DB_USERNAME}
password=${DB_PASSWORD}
EOF
chmod 600 "${CREDENTIALS_FILE}"

echo "Restoring ${BACKUP_FILE} ke database ${DB_DATABASE}..."
gunzip -c "${BACKUP_FILE}" | mysql --defaults-extra-file="${CREDENTIALS_FILE}" "${DB_DATABASE}"
echo "Restore selesai."
