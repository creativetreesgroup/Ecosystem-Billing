#!/usr/bin/env bash
set -euo pipefail

# Backup harian database billing (§11) — data uang tanpa backup teruji =
# tidak production-ready. Baca kredensial dari .env aplikasi, tidak
# hardcoded, supaya satu script ini valid di semua environment.
#
# APP_DIR/BACKUP_DIR bisa dioverride lewat environment variable (dipakai
# untuk uji restore lokal — lihat RUNBOOK.md); default cocok untuk instalasi
# production standar di deploy/nginx & deploy/supervisor.

APP_DIR="${APP_DIR:-/var/www/creative-trees-billing}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/creative-trees-billing}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

set -a
# shellcheck disable=SC1091
source "${APP_DIR}/.env"
set +a

# Gagal keras & jelas kalau direktori tujuan tidak bisa ditulis. Sebelumnya
# `mkdir -p` di direktori milik root cuma membuat `set -e` menghentikan script
# tanpa pesan, sehingga backup yang tidak pernah jalan terlihat seperti sukses.
if ! mkdir -p "${BACKUP_DIR}" 2>/dev/null || [ ! -w "${BACKUP_DIR}" ]; then
  echo "FATAL: ${BACKUP_DIR} tidak bisa ditulis oleh $(id -un)." >&2
  echo "Jalankan sekali sebagai root:" >&2
  echo "  sudo mkdir -p ${BACKUP_DIR} && sudo chown $(id -un):$(id -gn) ${BACKUP_DIR} && sudo chmod 750 ${BACKUP_DIR}" >&2
  exit 1
fi

# --defaults-extra-file (bukan --password= di argumen CLI) supaya kredensial
# DB tidak terlihat siapa pun yang menjalankan `ps aux` selama dump berjalan
# (§9.4: secret dilarang bocor lewat jalur yang tidak perlu).
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

DEST="${BACKUP_DIR}/${DB_DATABASE}-${TIMESTAMP}.sql.gz"

mysqldump \
  --defaults-extra-file="${CREDENTIALS_FILE}" \
  --single-transaction \
  --routines \
  --triggers \
  "${DB_DATABASE}" | gzip > "${DEST}"

find "${BACKUP_DIR}" -name "${DB_DATABASE}-*.sql.gz" -mtime "+${RETENTION_DAYS}" -delete

echo "Backup selesai: ${DEST}"
