#!/usr/bin/env bash
#
# Instalasi TURNKEY Creative Trees Billing (Docker) — sekali jalan, langsung siap.
#
#   ./install.sh
#
# Semua kunci & password dibuat otomatis, IP LAN dideteksi sendiri, image
# dibangun, stack + monitoring dinyalakan, lalu owner pertama dibuat. Aman
# diulang: kunci yang sudah ada tidak ditimpa.
#
# Opsi (semua opsional, lewat environment variable):
#   SERVER_IP=192.168.1.10          # paksa IP server (kalau deteksi salah)
#   OWNER_NAME / OWNER_EMAIL / OWNER_PASSWORD   # buat owner tanpa tanya-jawab
#   WITH_MONITORING=0               # lewati Grafana/Prometheus (default: nyala)
#
set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '\033[1;36m[install]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[install]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[install] %s\033[0m\n' "$*" >&2; exit 1; }

# --- 0. Prasyarat --------------------------------------------------------------
command -v docker >/dev/null            || die "Docker belum terpasang. Lihat README bagian Instalasi."
docker compose version >/dev/null 2>&1  || die "Docker Compose v2 tidak tersedia."
command -v openssl >/dev/null           || die "openssl dibutuhkan untuk membuat kunci acak."
docker info >/dev/null 2>&1             || die "Docker daemon tidak berjalan. Nyalakan Docker dulu."

# --- 1. .env -------------------------------------------------------------------
if [ ! -f .env ]; then
    cp .env.docker .env
    say ".env dibuat dari .env.docker"
fi

# Baca nilai .env: buang komentar sebaris (" # …"), tanda kutip, & spasi ekor.
get_env() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -E 's/[[:space:]]+#.*$//; s/^"(.*)"$/\1/; s/[[:space:]]+$//'; }
set_env() {
    K="$1" V="$2" perl -i -pe 'BEGIN{$k=$ENV{K};$v=$ENV{V}} s/^\Q$k\E=.*/"$k=$v"/e' .env
    grep -qE "^$1=" .env || printf '%s=%s\n' "$1" "$2" >> .env
}
rand() { openssl rand -hex 16; }
# "kosong" = benar-benar kosong atau masih nilai placeholder dari .env.docker.
is_blank() {
    local v; v="$(get_env "$1")"
    case "$v" in ''|null|change_me|change_me_root|change_me_grafana) return 0 ;; *) return 1 ;; esac
}

# --- 2. IP LAN -----------------------------------------------------------------
detect_ip() {
    if command -v ipconfig >/dev/null 2>&1; then ipconfig getifaddr en0 2>/dev/null && return 0; fi
    hostname -I 2>/dev/null | awk '{print $1}'
}
IP="${SERVER_IP:-$(detect_ip || true)}"; IP="${IP:-192.168.1.10}"
say "IP server terdeteksi: $IP  (ganti dengan SERVER_IP=... bila keliru)"
set_env APP_URL "http://$IP"
set_env VITE_REVERB_HOST "$IP"

# --- 3. Kunci & password (hanya bila belum diisi → aman diulang) ---------------
is_blank APP_KEY           && set_env APP_KEY "base64:$(openssl rand -base64 32)"
is_blank DB_PASSWORD       && set_env DB_PASSWORD "$(rand)"
is_blank DB_ROOT_PASSWORD  && set_env DB_ROOT_PASSWORD "$(rand)"
is_blank GRAFANA_PASSWORD  && set_env GRAFANA_PASSWORD "$(rand)"
is_blank REVERB_APP_ID     && set_env REVERB_APP_ID "$(( (RANDOM << 15 | RANDOM) % 900000 + 100000 ))"
is_blank REVERB_APP_KEY    && set_env REVERB_APP_KEY "$(rand)"
is_blank REVERB_APP_SECRET && set_env REVERB_APP_SECRET "$(rand)"
say "Kunci aplikasi, Reverb, dan password DB/Grafana sudah terisi di .env"

# --- 4. Build + jalankan -------------------------------------------------------
PROFILE=()
[ "${WITH_MONITORING:-1}" = "0" ] || PROFILE=(--profile monitoring)

say "Membangun image (pertama kali bisa beberapa menit)…"
docker compose build
say "Menjalankan stack…"
docker compose "${PROFILE[@]}" up -d

# --- 5. Tunggu aplikasi sehat (migrasi jalan otomatis di entrypoint) ----------
say "Menunggu aplikasi siap…"
for _ in $(seq 1 80); do
    [ "$(docker compose ps app --format '{{.Health}}' 2>/dev/null)" = "healthy" ] && break
    sleep 3
done
[ "$(docker compose ps app --format '{{.Health}}' 2>/dev/null)" = "healthy" ] \
    || warn "Aplikasi belum 'healthy' setelah menunggu — cek: docker compose logs app"

# --- 6. Owner pertama (hanya bila belum ada user) -----------------------------
USER_COUNT="$(docker compose exec -T app php artisan tinker --execute 'echo \App\Models\User::count();' 2>/dev/null | tr -dc '0-9' | tail -c2)"
if [ "${USER_COUNT:-0}" = "0" ] || [ -z "${USER_COUNT:-}" ]; then
    if [ -n "${OWNER_EMAIL:-}" ]; then
        docker compose exec -T app php artisan app:create-owner \
            --name="${OWNER_NAME:-Owner}" --email="$OWNER_EMAIL" \
            --password="${OWNER_PASSWORD:?Set OWNER_PASSWORD untuk mode non-interaktif}" || true
    else
        say "Buat akun owner pertama:"
        docker compose exec app php artisan app:create-owner || true
    fi
else
    say "Owner sudah ada — melewati pembuatan akun."
fi

# --- 7. Selesai ----------------------------------------------------------------
say "SELESAI ✅"
echo
say "Panel kasir/owner : http://$IP"
[ "${WITH_MONITORING:-1}" = "0" ] || say "Grafana           : http://$IP:3000  (admin / $(get_env GRAFANA_PASSWORD))"
say "Matikan           : docker compose ${PROFILE[*]} down     (data aman di volume)"
say "Nyalakan lagi     : docker compose ${PROFILE[*]} up -d"
