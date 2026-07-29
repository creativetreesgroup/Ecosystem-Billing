# Kebijakan Keamanan

Sistem ini menangani uang sungguhan: saldo pelanggan, pembayaran QRIS, dan
transaksi outlet. Laporan keamanan diperlakukan dengan prioritas tertinggi.

## Versi yang didukung

| Versi | Dukungan keamanan |
|-------|-------------------|
| `main` | Ya |
| Branch fitur | Tidak |
| Fork pihak ketiga | Tidak |

Repository ini belum memakai rilis bertag. Sampai `CHANGELOG.md` mencantumkan
versi rilis, hanya `main` yang menerima perbaikan keamanan.

## Melaporkan kerentanan

**Jangan membuka public issue untuk kerentanan keamanan.**

Gunakan salah satu kanal privat berikut:

1. **GitHub Private Vulnerability Reporting** — tab *Security* → *Report a
   vulnerability*. Ini kanal yang diutamakan.
2. Kanal kontak resmi Creative Trees Group yang tercantum di halaman organisasi
   GitHub.

> Kontak email khusus keamanan belum ditetapkan. Alamat email sengaja tidak
> dicantumkan agar tidak ada alamat fiktif yang membuat laporan hilang tanpa
> dibaca. Ganti bagian ini setelah alamat resmi tersedia.

### Yang perlu disertakan

- Deskripsi kerentanan dan dampaknya
- Langkah reproduksi
- Versi/commit yang terpengaruh
- Bukti dampak bila ada (log, screenshot, request)
- **Jangan** menyertakan secret sungguhan, token, atau data pelanggan asli.
  Gunakan nilai yang sudah disamarkan.

### Proses yang diharapkan

| Tahap | Target |
|-------|--------|
| Konfirmasi penerimaan | 3 hari kerja |
| Penilaian awal & severity | 7 hari kerja |
| Perbaikan untuk severity kritis | Secepat mungkin, dengan kabar berkala |
| Disclosure terkoordinasi | Setelah perbaikan tersedia dan ter-deploy |

Target ini komitmen upaya, bukan SLA kontraktual.

## Area yang sangat sensitif

Laporan pada area berikut ditangani lebih dulu:

- **Integritas uang** — double credit, double debit, saldo negatif, race
  condition pada wallet, pembayaran dikreditkan dua kali
- **Sesi** — sesi aktif ganda pada satu unit, sesi yang tidak pernah tertutup
- **Otorisasi** — eskalasi peran, akses lintas outlet, IDOR pada data pelanggan
- **Pembayaran** — manipulasi status QRIS, rekonsiliasi yang bisa dipalsukan
- **Secret** — kebocoran token integrasi lewat log, UI, atau response

## Kontrol yang sudah berlaku

Diverifikasi oleh test otomatis atau konfigurasi, bukan sekadar pernyataan:

| Kontrol | Bukti |
|---------|-------|
| `.env` tidak pernah masuk git | Riwayat git bersih; ada di `.gitignore` |
| Secret tidak bocor ke log | `tests/Feature/Security/NoSecretsInLogsTest.php` |
| Secret integrasi tidak bocor ke UI | `tests/Feature/Filament/IntegrationSecretsTest.php` |
| Nilai uang bukan float | Seluruh kolom uang bertipe `integer` di migrasi |
| Pembayaran idempoten | `ApplySettledPaymentAction` + `ReconcileSettledPaymentsTest` |
| Race condition sesi diuji | `tests/Concurrency/` dengan proses anak sungguhan |
| Database & Redis tidak terekspos | Tidak ada `ports:` pada service `db`/`redis` |
| Kredensial produksi dibuat acak | `install.sh` |
| Test tidak bisa menyentuh DB produksi | Service `test` tanpa `env_file` + penjaga di `tests/TestCase.php` |

## Batasan yang diketahui

Disebutkan terbuka agar tidak disalahpahami sebagai sudah aman:

- Restore backup **belum pernah diuji**
- Belum ada endpoint health/readiness
- `composer audit` baru dijalankan lewat CI, belum ada baseline historis
- Realtime (Reverb) belum diuji end-to-end
- Grafana terekspos di port host dengan kredensial admin dari `.env`
- `cadvisor` berjalan dengan `privileged: true`

Rinciannya di [`docs/audits/REPOSITORY_AUDIT.md`](docs/audits/REPOSITORY_AUDIT.md).

## Bug bounty

Program bug bounty berbayar **belum tersedia**. Laporan yang valid tetap
diapresiasi dan akan dicantumkan dalam catatan rilis bila pelapor bersedia.
