# Repository Audit — Ecosystem-Billing

**Tanggal audit:** 2026-07-28
**Terakhir diperbarui:** 2026-07-28 — setelah siklus perbaikan; lihat bagian G
**Yang diaudit:** `feat/kiosk-v1-code-complete`, 69 commit di depan `origin/main`
**Metode:** verifikasi terhadap source code, migrasi, test, dan konfigurasi Docker. Klaim README tidak diterima sebagai bukti.

> Dokumen ini adalah audit, bukan materi promosi. Setiap baris berstatus
> `Verified`, `Partial`, `Planned`, atau `Not verified`, dan status itu
> ditentukan oleh bukti yang bisa dibuka ulang pembaca, bukan oleh keyakinan
> penulisnya.

---

## A. Executive Summary

**Tujuan proyek.** Sistem billing rental PlayStation untuk outlet: sesi bermain,
tarif, saldo pelanggan, pembayaran, pemesanan menu, kontrol TV/perangkat, dan
operasional harian kasir. Berjalan di jaringan lokal outlet (LAN-first), bukan
sebagai SaaS multi-tenant publik.

**Target pengguna.** Owner outlet, kasir, teknisi lapangan, dan engineer internal
Creative Trees Group.

**Tingkat kesiapan saat ini.** Aplikasinya matang secara fungsional — 10 domain,
21 model, 15 perintah artisan operasional, 100 berkas test, dan seluruh suite
(583 test) lulus di dalam Docker. Yang belum matang adalah **kelengkapan
repository sebagai produk engineering**: tidak ada CI, tidak ada dokumentasi
terstruktur, dan tidak ada berkas tata kelola.

**Kekuatan terbesar.**

1. Integritas uang dijaga di tingkat skema: seluruh kolom nilai uang bertipe
   `integer`, tidak satu pun `float`/`double`.
2. Concurrency diuji dengan proses anak sungguhan, bukan disimulasikan.
3. Jalur pembayaran punya aksi idempoten yang diuji.
4. Instalasi Docker bersifat turnkey satu perintah dan terbukti jalan dari
   volume kosong.

**Kekurangan terbesar saat audit dimulai.** Keempatnya sudah ditutup dalam
siklus ini — rinciannya di bagian G:

1. Tidak ada CI sama sekali — tidak ada yang menahan regresi masuk ke `main`.
2. README 1.722 baris merangkap arsitektur, runbook, troubleshooting, dan
   panduan instalasi sekaligus; tidak ada `docs/`.
3. Tidak ada `LICENSE`, `SECURITY.md`, atau `CONTRIBUTING.md`, padahal
   repository akan dipublikasikan.
4. Tidak ada endpoint health/readiness untuk orkestrator.

**Kekurangan yang masih terbuka.** Restore backup belum pernah diuji, belum ada
alerting, dan empat integrasi masih berstatus `Partial`. Lihat bagian H.

**Risiko tertinggi (sudah diperbaiki dalam siklus ini).** Perintah yang
didokumentasikan sendiri di README —
`docker compose exec app php artisan test` — menjalankan test terhadap
**database produksi**. Rinciannya di bagian E.

**Prioritas perbaikan.**

| # | Prioritas | Status |
|---|-----------|--------|
| 1 | CI wajib hijau sebelum merge | **Selesai** — 3 job, terbukti hijau di PR #1 |
| 2 | Pisahkan README ke `docs/` | **Sebagian** — README 1.722 → 243 baris; pemecahan tematik berlanjut |
| 3 | `LICENSE` + `SECURITY.md` | **Selesai** |
| 4 | Health/readiness endpoint | **Selesai** — `/health` dan `/ready`, 5 test |
| 5 | Uji restore backup | **Belum** — risiko tertinggi yang tersisa |

---

## B. Repository Inventory

### Domain aplikasi (`app/Domain/`)

`Billing`, `Customers`, `Devices`, `Discounts`, `Kiosk`, `Menu`, `Sessions`,
`Settings`, `Users`, `Wallet`

### Model (21)

`Customer`, `DeviceAlert`, `Discount`, `DiscountRedemption`, `Integration`,
`MenuCategory`, `MenuItem`, `MenuOrder`, `MenuOrderItem`, `OtpCode`, `Outlet`,
`Package`, `Payment`, `RentalSession`, `Role`, `SessionExtension`, `Setting`,
`Unit`, `UnitType`, `User`, `WalletTransaction`

### Perintah artisan operasional (15)

| Perintah | Peran |
|----------|-------|
| `app:create-owner` | Membuat owner + outlet pertama saat instalasi |
| `payments:poll-qris` | Menarik status pembayaran QRIS |
| `payments:reconcile-settled` | Merekonsiliasi pembayaran yang sudah settle |
| `sessions:sweep-expired` | Menutup sesi kedaluwarsa |
| `sessions:enforce-open-play-ceiling` | Menegakkan batas atas open play |
| `menu:refund-stale-orders` | Mengembalikan dana pesanan menu yang basi |
| `wallet:audit-balances` | Mengaudit konsistensi saldo |
| `units:discover`, `units:scan-network` | Menemukan perangkat di LAN |
| `units:poll-power-state` | Memantau status daya unit |
| `tv:doctor`, `tv:show-idle-screens` | Diagnostik & tampilan TV |
| `mqtt:bridge-listen` | Jembatan MQTT (Tasmota) |
| `otp:show-latest` | Membantu debug OTP |

### Docker

**Service aplikasi (7):** `app` (PHP-FPM), `web` (nginx), `reverb` (WebSocket),
`worker` (queue), `scheduler`, `db` (MySQL 8.4), `redis`.
**Profil `monitoring` (5):** `prometheus`, `grafana`, `cadvisor`,
`mysql-exporter`, `redis-exporter`.
**Profil `host-metrics` (1):** `node-exporter` — Linux saja.
**Profil `test` (1):** `test` — lihat bagian E.

### Test

102 berkas test, 3 testsuite (`Unit`, `Feature`, `Concurrency`).
**583 test lulus, 1.456 assertion**, dijalankan di dalam Docker pada 2026-07-28.

### Berkas tata kelola

Ditambahkan dalam siklus ini: `.github/` (CI, template issue & PR, CODEOWNERS,
dependabot), `docs/`, `LICENSE`, `SECURITY.md`, `CONTRIBUTING.md`,
`SUPPORT.md`, `ROADMAP.md`.

Masih belum ada: `CODE_OF_CONDUCT.md`, `GOVERNANCE.md`, dan workflow rilis —
yang terakhir sengaja ditunda sampai strategi versioning ditetapkan.

---

## C. Feature Verification Matrix

| Fitur | README | Source code | Test | Status | Catatan |
|-------|--------|-------------|------|--------|---------|
| Billing sesi rental | Diklaim | `app/Domain/Sessions`, `app/Domain/Billing` | Ada — `OpenPlayBillingCalculatorTest` (11 kasus), `CompleteSessionActionTest` | **Verified** | Pembulatan increment, clock skew negatif, dan batas int diuji eksplisit |
| Uang sebagai integer | Diklaim | Migrasi: `integer('amount')`, `integer('balance')`, `integer('balance_after')` | Ada — via test billing | **Verified** | Tidak ditemukan satu pun kolom uang bertipe `float`/`double`/`decimal` |
| Saldo pelanggan (wallet) | Diklaim | `app/Domain/Wallet`, `WalletTransaction` | Ada + `wallet:audit-balances` | **Verified** | Ledger menyimpan `balance_after` sehingga saldo dapat direkonstruksi |
| Concurrency saldo & sesi | Diklaim | — | Ada — `StartSessionConcurrencyTest`, `StopOpenPlayConcurrencyTest` | **Verified** | Memakai `DatabaseTruncation` + proses anak sungguhan, bukan mock |
| Idempotensi pembayaran | Diklaim | `ApplySettledPaymentAction`, `ReconcileSettledPayments` | Ada — `ReconcileSettledPaymentsTest` | **Verified** | Aksi menolak mengkredit pembayaran yang sama dua kali |
| QRIS | Diklaim | `PollQrisPayments` | Ada — `PollQrisPaymentsTest` | **Verified** | Berbasis polling, bukan webhook |
| Menu & pesanan | Diklaim | `app/Domain/Menu`, 4 model | Ada — termasuk `OrderingIsRefusedWhenClosedTest` | **Verified** | Jam buka, jeda istirahat, dan sakelar owner diuji |
| Diskon / voucher | Diklaim | `app/Domain/Discounts`, `DiscountRedemption` | Ada | **Verified** | — |
| Kontrol TV / perangkat | Diklaim | `app/Domain/Devices`, `tv:doctor`, `WakeOnLan` | Ada — `TvDoctorTest`, `WakeOnLanTest` | **Verified** | Ekstensi PHP `sockets` sempat absen dari image; diperbaiki siklus ini |
| MQTT / Tasmota | Diklaim | `MqttBridgeListen`, `TasmotaTopic` | Ada — `TasmotaTopicTest` | **Verified** | — |
| Realtime (Reverb) | Diklaim | Service `reverb`, `BROADCAST_CONNECTION=reverb` | Tidak ditemukan test end-to-end WebSocket | **Partial** | Service berjalan & healthy; jalur siaran ke browser belum diuji otomatis |
| Home Assistant | Diklaim | `HA_BASE_URL`, `HA_TOKEN`, `docker/home-assistant/` | Tidak ditemukan test | **Partial** | Konfigurasi ada; integrasi belum terverifikasi otomatis |
| WAHA (WhatsApp OTP) | Diklaim | `WAHA_BASE_URL` di `.env.docker`, `OtpCode` | Test OTP ada, pengiriman WhatsApp tidak | **Partial** | Kredensial kosong secara bawaan |
| Telegram notifikasi | Diklaim | `TELEGRAM_BOT_TOKEN` di `.env.docker` | Tidak ditemukan test | **Partial** | — |
| Monitoring (Grafana/Prometheus) | Diklaim | Profil `monitoring`, dashboard + datasource ter-provision | Tidak ada test otomatis | **Verified (manual)** | Grafana merespons HTTP 200; dashboard `system-overview.json` ada |
| Health & readiness endpoint | Diklaim | `app/Http/Controllers/HealthController.php` | Ada — `HealthEndpointTest` (5 test) | **Verified** | Liveness dipisah dari readiness; kebocoran pesan error diuji |
| CI/CD | Tidak diklaim | Tidak ada `.github/workflows/` | — | **Not implemented** | Prioritas 1 |
| Backup & restore | Diklaim di README | `deploy/backup/*.sh` dirujuk README | Tidak ditemukan test restore | **Not verified** | Restore belum pernah dibuktikan berhasil |

---

## D. Documentation Gap

| Masalah | Bukti | Dampak |
|---------|-------|--------|
| README merangkap segalanya | 1.722 baris: hero, arsitektur, instalasi, runbook, troubleshooting, ADR | Tidak terpelihara; pembaca baru tidak tahu harus mulai dari mana |
| Tidak ada `docs/` | Direktori tidak ada | Tidak ada tempat untuk dokumen teknis panjang |
| Instruksi berisiko | README §13.1 menyarankan `docker compose exec app php artisan test` | Menjalankan test di database produksi — lihat bagian E |
| Angka test ditulis manual | README menyebut "572 test" | Basi begitu suite berubah; harus digantikan badge CI |
| Klaim tanpa bukti | Backup/restore didokumentasikan tanpa verifikasi restore | Prosedur pemulihan yang belum pernah diuji sama dengan tidak ada |
| Panduan non-Docker & Docker bercampur | §5.5 (native, MySQL port 3307) vs §5.2 (Docker) | Pembaca mudah menjalankan perintah dari jalur yang salah |

---

## E. Security Gap

### E.1 Temuan kritis — test menyentuh database produksi (**diperbaiki**)

**Severity:** Critical — potensi kehilangan seluruh data transaksi.

**Rantai penyebabnya berlapis:**

1. Service `app` memakai `env_file: .env`, sehingga `$_SERVER` berisi
   `DB_DATABASE` dan `APP_ENV` milik **aplikasi**.
2. Elemen `<env>` di `phpunit.xml` hanya berlaku bila variabel belum ada di
   proses — jadi seluruh konfigurasi test diabaikan diam-diam.
3. `force="true"` pun tidak cukup: PHPUnit menerapkannya lewat `putenv()` saja,
   sedangkan Laravel membaca `$_SERVER` lebih dulu.
4. Akibatnya `RefreshDatabase` menjalankan `migrate:fresh` — `DROP` seluruh
   tabel — terhadap database outlet sungguhan.

**Mengapa data tidak hilang.** `migrate:fresh` dipanggil tanpa `--force`,
sehingga `ConfirmableTrait` menahannya karena `APP_ENV` terbaca `production`.
Itu keberuntungan, bukan rancangan — dan itu pula sebabnya suite tampak gagal
491 test: migrasinya memang tidak pernah berjalan.

**Mitigasi (tiga lapis, sudah diterapkan):**

1. Service `test` terpisah di `docker-compose.yml` yang **sengaja tidak**
   membaca `.env`.
2. `force="true"` pada `DB_DATABASE` di `phpunit.xml`.
3. Penjaga keras di `tests/TestCase.php`: menolak berjalan bila nama database
   tidak berakhiran `_test`.

### E.2 Temuan lain

| Area | Status | Catatan |
|------|--------|---------|
| Secret dalam repository | **Aman** | `.env` tidak pernah ter-commit; ada di `.gitignore` |
| `APP_DEBUG` produksi | **Aman** | `.env.docker` menetapkan `APP_DEBUG=false`, `APP_ENV=production` |
| Secret dalam log | **Diuji** | `tests/Feature/Security/NoSecretsInLogsTest.php` |
| Secret pada UI integrasi | **Diuji** | `tests/Feature/Filament/IntegrationSecretsTest.php` |
| Database & Redis terekspos | **Aman** | Tidak ada `ports:` pada service `db`/`redis`; hanya jaringan internal `ctb` |
| Kredensial acak | **Aman** | `install.sh` membuat `APP_KEY`, password DB/root/Grafana, dan kunci Reverb secara acak |
| Privilege Docker | **Perlu perhatian** | `cadvisor` berjalan `privileged: true`; `node-exporter` memakai PID namespace host (Linux saja) |
| Grafana terekspos | **Perlu perhatian** | Port 3000 terbuka di host dengan kredensial admin dari `.env` |
| Role escalation | **Sebagian** | Shield + policy ada (`RolePolicy` melindungi `super_admin`); belum ada test khusus eskalasi |
| Rate limiting | **Not verified** | Belum diperiksa dalam audit ini |
| Dependency vulnerability | **Not run** | `composer audit` belum dijalankan pada siklus ini |

---

## F. Operational Gap

| Kebutuhan | Status | Catatan |
|-----------|--------|---------|
| Health check container | **Ada** | `app` (fpm-healthcheck), `db`, `redis`, `reverb`, `cadvisor` |
| Health check aplikasi (`/health`, `/ready`) | **Ada** | Liveness tanpa dependensi; readiness mengecek DB, Redis, storage. Healthcheck nginx memakai `/health` |
| Scheduler heartbeat | **Tidak ada** | `schedule:work` berjalan, tetapi kegagalannya tidak terpantau |
| Queue failure & retry | **Sebagian** | `--tries=3` di service `worker`; strategi dead-letter belum terdokumentasi |
| Reverb availability | **Ada** | Healthcheck TCP pada port 8080 |
| Backup | **Tidak terverifikasi** | Skrip dirujuk README; keberhasilannya belum diuji |
| Restore | **Tidak terverifikasi** | Belum pernah dibuktikan — risiko operasional tertinggi yang tersisa |
| Durabilitas MySQL | **Ada** | `innodb-flush-log-at-trx-commit=1` dan `sync-binlog=1` ditulis eksplisit |
| Log rotation & disk | **Tidak terverifikasi** | `app-logs` adalah volume Docker tanpa kebijakan rotasi terdokumentasi |
| Alerting | **Tidak ada** | Prometheus mengumpulkan metrik; tidak ditemukan aturan alert |

---

## G. Perbaikan yang dilakukan pada siklus audit ini

| Perubahan | Jenis | Bukti |
|-----------|-------|-------|
| Service `test` tanpa `env_file` | Security — kritis | Suite 573 test lulus di database `_test` |
| `force="true"` pada `DB_DATABASE` | Security — kritis | `phpunit.xml` |
| Penjaga nama database | Security — kritis | `tests/TestCase.php` |
| Seeder peran di `entrypoint.sh` | Bug instalasi | Instalasi bersih berhasil tanpa langkah manual |
| `CreateOwner` transaksional | Integritas data | Test regresi baru ditambahkan |
| Ekstensi PHP `sockets` | Bug produksi | `WakeOnLan` memanggil `socket_create()`; tanpa ini menyalakan PS5 gagal |
| `node-exporter` ke profil `host-metrics` | Reliability | `--profile monitoring up` tidak lagi gagal di Docker Desktop |
| Init SQL database test | Operasional | Database test dibuat otomatis saat volume MySQL diinisialisasi |
| Konfigurasi bawaan diwujudkan jadi baris | Usability & auditability | Layar Pengaturan/Integrasi tidak lagi kosong; 5 test, termasuk anti-timpa saat restart |
| Endpoint `/health` & `/ready` | Observabilitas | 5 test; liveness dipisah dari readiness, healthcheck nginx memakai `/health` |
| CI tiga job | Reliability | Terbukti hijau di PR #1 setelah dua perbaikan workflow |
| README, audit, tata kelola | Maintainability | README 1.722 → 243 baris; isi lama utuh di `docs/LEGACY_README.md` |

---

## H. Risiko yang masih terbuka

| Risiko | Severity | Rekomendasi |
|--------|----------|-------------|
| Restore backup belum pernah diuji | **Tinggi** | Latihan restore ke lingkungan terpisah, dokumentasikan hasilnya |
| Tidak ada CI | **Tinggi** | Wajibkan test + Pint hijau sebelum merge |
| Tidak ada alerting | **Sedang** | Aturan Prometheus untuk queue gagal, scheduler diam, disk menipis |
| Realtime belum diuji end-to-end | **Sedang** | Test siaran Reverb ke klien |
| `composer audit` belum dijalankan | **Sedang** | Jalankan di CI |
| Grafana terekspos di port host | **Rendah** | Batasi ke jaringan tepercaya atau taruh di belakang reverse proxy |

---

## I. Metode & keterbatasan

Audit ini membaca source code, migrasi, test, dan konfigurasi Docker pada
commit yang disebut di kepala dokumen. Yang **tidak** dilakukan:

- Penetration testing
- `composer audit` / pemindaian CVE dependency
- Load testing
- Uji restore backup
- Review keamanan perangkat keras jaringan outlet

Bagian mana pun yang bertanda `Not verified` berarti belum diperiksa, **bukan**
berarti aman.
