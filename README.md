<div align="center">

# Ecosystem Billing

**Sistem billing rental PlayStation untuk outlet — sesi, tarif, saldo, pembayaran, dan kontrol perangkat dalam satu platform.**

[![CI](https://github.com/creativetreesgroup/Ecosystem-Billing/actions/workflows/ci.yml/badge.svg)](https://github.com/creativetreesgroup/Ecosystem-Billing/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20)](composer.json)
[![License](https://img.shields.io/badge/license-Proprietary-lightgrey)](LICENSE)

</div>

---

## Status proyek

**Aktif dikembangkan.** Berjalan penuh di Docker dengan 604 test otomatis lulus.
Belum ada rilis bertag; `main` adalah satu-satunya versi yang didukung.

Kesiapan per area diaudit dan didokumentasikan secara terbuka di
[`docs/audits/REPOSITORY_AUDIT.md`](docs/audits/REPOSITORY_AUDIT.md) — termasuk
bagian yang **belum** terverifikasi. Klaim di README ini hanya mencakup yang
dibuktikan oleh source code, test, atau konfigurasi.

---

## Kemampuan utama

| Kemampuan | Status | Bukti |
|-----------|--------|-------|
| Billing sesi rental — tarif per jam, paket, open play, perpanjangan | Verified | `app/Domain/Sessions`, `app/Domain/Billing`, 11 kasus uji pembulatan |
| Saldo pelanggan berbasis ledger | Verified | `WalletTransaction` menyimpan `balance_after`; `wallet:audit-balances` |
| Pembayaran QRIS dengan rekonsiliasi idempoten | Verified | `ApplySettledPaymentAction`, `ReconcileSettledPaymentsTest` |
| Pemesanan menu dengan jam buka & jeda istirahat | Verified | `app/Domain/Menu`, `OrderingIsRefusedWhenClosedTest` |
| Diskon & voucher dengan pencatatan penukaran | Verified | `app/Domain/Discounts`, `DiscountRedemption` |
| Kontrol TV & unit — Wake-on-LAN, MQTT/Tasmota, diagnostik | Verified | `tv:doctor`, `WakeOnLan`, `TasmotaTopic` |
| Panel owner & kasir (Filament) dengan peran berbutir halus | Verified | 11 peran, 217 izin dari Shield |
| Identitas usaha dapat diubah dari panel — nama, logo, favicon | Verified | `SettingKey::BusinessName/BrandLogo/BrandFavicon`, 10 test termasuk mitigasi XSS SVG |
| Monitoring — Grafana, Prometheus, exporter | Verified (manual) | Profil `monitoring`, dashboard ter-provision |
| Realtime (Reverb) | Sebagian | Service berjalan; jalur siaran ke browser belum diuji otomatis |
| Home Assistant, WAHA (WhatsApp OTP), Telegram | Sebagian | Konfigurasi ada; integrasi belum terverifikasi otomatis |
| Health & readiness endpoint | Verified | `/health` (liveness) dan `/ready` (DB, Redis, storage), 5 test |

### Pemantauan

```bash
curl http://<IP-server>/health   # {"status":"ok"}                 — liveness
curl http://<IP-server>/ready    # {"status":"ready","checks":{…}} — readiness
```

`/health` sengaja tidak menyentuh dependensi apa pun. Kalau ia ikut mengecek
database, MySQL yang sedang restart akan membuat orkestrator membunuh aplikasi
yang sebenarnya sehat — restart loop di tengah jam sibuk. `/ready` menjawab
`503` saat dependensi jatuh, dan tidak pernah membocorkan pesan error
internal ke pemanggil anonim.

### Dua jaminan yang ditegakkan di tingkat kode

**Uang tidak pernah berupa float.** Seluruh kolom nilai uang bertipe `integer`.
Pembulatan biner tidak bisa dipertanggungjawabkan kepada pelanggan.

**Concurrency diuji dengan proses sungguhan.** `tests/Concurrency/` menjalankan
proses anak yang benar-benar berlomba di database yang sama — bukan mock — untuk
membuktikan satu unit tidak bisa punya dua sesi aktif.

---

## Arsitektur

```
                          ┌──────────────────────────┐
   Browser kasir ────────▶│  web (nginx)  :80        │
   Kiosk pelanggan        └────────┬─────────────────┘
   TV / display                    │ FastCGI          │ WebSocket /app
                                   ▼                  ▼
                         ┌───────────────┐   ┌────────────────┐
                         │ app (PHP-FPM) │   │ reverb  :8080  │
                         └───────┬───────┘   └────────────────┘
                                 │
          ┌──────────────────────┼──────────────────────┐
          ▼                      ▼                      ▼
   ┌─────────────┐      ┌────────────────┐     ┌────────────────┐
   │ db (MySQL)  │      │ redis          │     │ worker         │
   │  8.4        │      │  cache + queue │     │ scheduler      │
   └─────────────┘      └────────────────┘     └────────────────┘
                                 │
                                 ▼ LAN outlet
                    PS5 · TV · smart plug (Tasmota/MQTT)
```

Satu image Docker menjalankan **semua** peran aplikasi — `app`, `reverb`,
`worker`, `scheduler`. Perannya ditentukan oleh `command` di
`docker-compose.yml`, bukan oleh image yang berbeda.

Arsitekturnya **LAN-first**: server berada di dalam jaringan outlet karena
kontrol perangkat membutuhkan mDNS dan Wake-on-LAN yang tidak melintasi
internet.

---

## Technology stack

| Lapisan | Teknologi |
|---------|-----------|
| Bahasa | PHP 8.4+ |
| Framework | Laravel 13 |
| Panel admin | Filament 5 + Shield |
| Realtime | Laravel Reverb 1 (WebSocket) |
| Database | MySQL 8.4 |
| Cache & queue | Redis 7 |
| Web server | nginx 1.27 |
| Testing | Pest 4 / PHPUnit 12 |
| Gaya kode | Laravel Pint |
| Container | Docker Compose |
| Monitoring | Prometheus, Grafana, cAdvisor, exporter MySQL/Redis |
| Perangkat | Home Assistant, MQTT (Mosquitto), Tasmota, Wake-on-LAN |

---

## Quick start

**Prasyarat:** Docker Engine + Docker Compose v2, `openssl`.

```bash
git clone https://github.com/creativetreesgroup/Ecosystem-Billing.git
cd Ecosystem-Billing
./install.sh
```

Installer akan: membuat `.env` dari `.env.docker`, membangkitkan seluruh kunci
dan password secara acak, mendeteksi IP LAN, membangun image, menyalakan stack
beserta monitoring, menjalankan migrasi dan seeder peran, lalu membuat akun
owner pertama.

Aman dijalankan berulang — nilai yang sudah terisi tidak ditimpa.

```bash
SERVER_IP=192.168.1.10 ./install.sh   # bila deteksi IP keliru
WITH_MONITORING=0 ./install.sh        # tanpa Grafana/Prometheus
```

Setelah selesai, panel tersedia di `http://<IP-server>` dan Grafana di
`http://<IP-server>:3000`.

### Operasi sehari-hari

```bash
docker compose --profile monitoring up -d      # nyalakan
docker compose --profile monitoring down       # matikan (data aman di volume)
docker compose logs -f app                     # log aplikasi
docker compose exec app php artisan tv:doctor  # diagnostik perangkat
```

> Di host **Linux**, tambahkan `--profile host-metrics` untuk menyertakan
> `node-exporter`. Profil itu dipisah karena membutuhkan PID namespace host dan
> bind `/` — tidak tersedia di Docker Desktop, dan menyertakannya di sana
> membuat seluruh stack gagal naik.

Panduan lengkap: [`docs/LEGACY_README.md`](docs/LEGACY_README.md).

---

## Alur pemakaian

Satu unit bergerak di antara dua keadaan saja. Yang memindahkannya adalah
pemindaian pelanggan, bukan tindakan kasir.

```
        ┌──────────────────────────────────────────┐
        │  MENGANGGUR                              │
        │  TV menampilkan QR + kode & tipe unit    │
        └───────────────────┬──────────────────────┘
                            │  pelanggan memindai QR dari kursinya
                            ▼
        ┌──────────────────────────────────────────┐
        │  Pilih cara main → saldo terpotong        │
        │  TV menyala, QR HILANG dari layar         │
        └───────────────────┬──────────────────────┘
                            ▼
        ┌──────────────────────────────────────────┐
        │  SEDANG DIPAKAI                          │
        │  Pelanggan tetap bisa isi saldo & jajan  │
        └───────────────────┬──────────────────────┘
                            │  waktu habis, atau "Berhenti & bayar"
                            ▼
                     kembali MENGANGGUR
```

### Yang dilihat pelanggan

| Tahap | Di TV | Di HP pelanggan |
|-------|-------|-----------------|
| Menganggur | QR besar, kode unit (`PS-01`), tipe unit, harga termurah | — |
| Memindai | masih QR | Masuk pakai nomor WhatsApp, lalu pilih paket atau Open Play |
| Mulai main | **QR hilang**, TV menyala | Kartu "Sedang main" — hitung mundur, atau saldo yang turun per detik |
| Sedang main | tampilan game | Kartu sesi **di atas** dasbor; tile Isi saldo & Pesan tetap hidup |
| Selesai | QR kembali muncul | Ringkasan tagihan |

### Isi saldo & pesan makanan tanpa berhenti main

Pelanggan **tidak perlu** menghentikan sesinya untuk jajan. Tepat di bawah
waktu berjalan, di dalam kartu sesi, ada tiga tombol bulat: **Pesan**,
**Isi saldo**, dan **Riwayat**. Menekan salah satunya membuka panel yang
menggeser naik dari bawah layar — kartu sesi tetap terlihat di belakangnya,
lengkap dengan saldo yang turun per detik dan tombol berhenti.

Tile **Main** dimatikan selama bermain; memulai sesi kedua di unit yang sama
tidak masuk akal.

Pesanan dibayar dari saldo. Kalau saldo kurang, kios mengarahkan ke isi saldo
lebih dulu; sesi yang sedang berjalan tidak tersentuh sama sekali.

### Kapan QR muncul dan hilang

| Kejadian | Yang terjadi pada TV | Dipicu oleh |
|----------|----------------------|-------------|
| Unit menganggur | QR ditampilkan | `tv:show-idle` (terjadwal) |
| Sesi dimulai | TV dinyalakan, QR dihentikan | `powerOn()` + `clearScreen()` di aksi mulai sesi |
| Sesi berjalan | QR **tidak** dikembalikan | `tv:show-idle` melewati unit yang punya sesi aktif |
| Sesi selesai | QR muncul lagi | `tv:show-idle` pada jadwal berikutnya |

> Kontrol TV membutuhkan Home Assistant di jaringan outlet **dan** Docker Engine
> di Linux. Di macOS/Windows aplikasinya berjalan penuh, tetapi perintah ke TV
> tidak keluar dari container — lihat [matriks dukungan](docs/LEGACY_README.md).

### Menyiapkan unit baru

1. Buat **Tipe unit** (mis. `XIAOMI TV A PRO 43 INC - VIP 1`) beserta tarif per jam
2. Buat **Unit** dengan kode yang tercetak di layar (mis. `PS-01`)
3. Sambungkan TV ke LAN berkabel, beri **reservasi DHCP**, nyalakan *Networked Standby*
4. Isi kredensial **Home Assistant** di Pengaturan → Integrasi
5. Uji: `docker compose exec app php artisan tv:doctor`
6. Tempel QR-nya ke TV: `docker compose exec app php artisan tv:show-idle`

---

## Pengujian

```bash
docker compose --profile test run --rm test php artisan test --compact
```

**604 test lulus, 1.515 assertion** — Unit, Feature, dan Concurrency, terakhir
dijalankan 2026-07-28 di dalam Docker.

> **Jangan pernah `docker compose exec app php artisan test`.** Service `app`
> membaca `env_file: .env`, sehingga test akan memakai `APP_ENV=production`
> **dan database produksi**; `RefreshDatabase` lalu menjalankan `migrate:fresh`
> — `DROP` seluruh tabel outlet. Service `test` ada justru untuk mencegah ini:
> ia sengaja tidak membaca `.env`, dan `tests/TestCase.php` menolak berjalan
> bila nama database tidak berakhiran `_test`.

---

## Deployment produksi

Target produksi adalah **PC server Linux di dalam jaringan outlet**. macOS dan
Windows didukung untuk pengembangan dan demo, tetapi kontrol TV sungguhan
membutuhkan Docker Engine di Linux karena butuh jaringan host.

Sebelum menyalakan di outlet sungguhan:

- [ ] `APP_ENV=production` dan `APP_DEBUG=false` — bawaan `.env.docker`
- [ ] Seluruh password dan kunci dibangkitkan installer, bukan nilai contoh
- [ ] MySQL berjalan dengan `innodb-flush-log-at-trx-commit=1` dan `sync-binlog=1`
- [ ] Backup terjadwal **dan restore-nya sudah diuji** — lihat catatan risiko di bawah
- [ ] Port `db` dan `redis` tidak terekspos ke host (bawaan: tidak)
- [ ] Grafana dibatasi ke jaringan tepercaya

> **Risiko terbuka yang harus Anda ketahui:** prosedur restore backup **belum
> pernah diuji**. Sampai latihan restore dilakukan dan hasilnya dicatat, anggap
> pemulihan bencana belum terbukti. Ini risiko operasional tertinggi yang
> tersisa dan tercatat di [ROADMAP.md](ROADMAP.md).

---

## Dokumentasi

| Dokumen | Isi |
|---------|-----|
| [`docs/INDEX.md`](docs/INDEX.md) | Navigasi dokumentasi berdasarkan peran |
| [`docs/audits/REPOSITORY_AUDIT.md`](docs/audits/REPOSITORY_AUDIT.md) | Audit menyeluruh: matrix verifikasi fitur, celah keamanan & operasional |
| [`docs/LEGACY_README.md`](docs/LEGACY_README.md) | Panduan teknis lengkap — instalasi, topologi, runbook, troubleshooting |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Standar engineering, aturan database, aturan pengujian |
| [`SECURITY.md`](SECURITY.md) | Pelaporan kerentanan dan kontrol yang berlaku |
| [`ROADMAP.md`](ROADMAP.md) | Rencana kerja per prioritas |
| [`CHANGELOG.md`](CHANGELOG.md) | Riwayat perubahan |
| [`SUPPORT.md`](SUPPORT.md) | Kanal dukungan |

---

## Keamanan

Sistem ini menangani uang sungguhan. **Jangan melaporkan kerentanan lewat public
issue** — gunakan kanal privat di [`SECURITY.md`](SECURITY.md).

Kontrol yang sudah diverifikasi: `.env` tidak pernah masuk git, secret tidak
bocor ke log maupun UI (keduanya diuji otomatis), database dan Redis tidak
terekspos ke host, seluruh kredensial produksi dibangkitkan acak, dan test tidak
bisa menyentuh database produksi.

Batasan yang diketahui dicantumkan terbuka di
[`SECURITY.md`](SECURITY.md#batasan-yang-diketahui) agar tidak disalahpahami
sebagai sudah aman.

---

## Kontribusi

Pengembangan dilakukan tim engineering internal Creative Trees Group. Laporan
bug dan usulan fitur dari luar diterima lewat
[Issues](https://github.com/creativetreesgroup/Ecosystem-Billing/issues).
Baca [`CONTRIBUTING.md`](CONTRIBUTING.md) lebih dulu — perubahan pada billing,
pembayaran, dan saldo punya syarat pengujian tersendiri.

---

## Lisensi

Proprietary — © 2026 Creative Trees Group. Seluruh hak dilindungi.

Source code dapat dilihat publik untuk transparansi dan audit. **Dapat dilihat
bukan berarti bebas dipakai**: penggunaan, penyalinan, modifikasi, dan
pendistribusian memerlukan izin tertulis. Lihat [`LICENSE`](LICENSE).

<div align="center">

**Creative Trees Group**

</div>
