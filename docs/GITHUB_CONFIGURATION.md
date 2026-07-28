# Konfigurasi GitHub

Dokumen ini memisahkan konfigurasi yang **sudah diselesaikan lewat source code**
dari yang **hanya bisa diatur manusia lewat antarmuka GitHub**.

---

## A. Sudah diatur lewat source code

| Konfigurasi | Berkas |
|-------------|--------|
| Pipeline CI (test, audit dependency, build Docker) | `.github/workflows/ci.yml` |
| Template Pull Request | `.github/PULL_REQUEST_TEMPLATE.md` |
| Template Issue (bug, fitur) | `.github/ISSUE_TEMPLATE/` |
| Pengalihan laporan keamanan ke kanal privat | `.github/ISSUE_TEMPLATE/config.yml` |
| Pemilik kode per area | `.github/CODEOWNERS` |
| Pembaruan dependency otomatis | `.github/dependabot.yml` |
| Kebijakan keamanan | `SECURITY.md` |
| Lisensi | `LICENSE` |

---

## B. Harus diatur manual lewat antarmuka GitHub

Berikut **tidak bisa** diatur dari repository. Harus dikerjakan orang yang punya
hak admin. Daftar ini adalah checklist, bukan laporan yang sudah selesai.

### Pengaturan repository

- [ ] **Description**: `Sistem billing rental PlayStation — sesi, saldo, pembayaran, dan kontrol perangkat. Laravel 13 + Filament 5, berjalan penuh di Docker.`
- [ ] **Topics**: `laravel`, `filament`, `php`, `docker`, `billing`, `playstation`, `point-of-sale`, `mysql`, `redis`, `reverb`, `iot`, `mqtt`
- [ ] **Default branch**: `main`

### Branch protection pada `main`

- [ ] Wajib Pull Request sebelum merge
- [ ] Wajib minimal 1 approving review
- [ ] Wajib review dari Code Owner untuk area billing/wallet/migrations
- [ ] Wajib status check hijau: `Test suite`, `Dependency audit`, `Docker build`
- [ ] Wajib branch mutakhir sebelum merge
- [ ] Larang force push dan penghapusan branch

### Keamanan

- [ ] Aktifkan **Private vulnerability reporting** — dirujuk `SECURITY.md`
- [ ] Aktifkan **Dependabot alerts**
- [ ] Aktifkan **Dependabot security updates**
- [ ] Aktifkan **Secret scanning** dan **Push protection**
- [ ] Pertimbangkan **CodeQL** — belum diuji kompatibilitasnya dengan proyek ini

### Lain-lain

- [ ] Buat tim `@creativetreesgroup/engineering` — dirujuk `CODEOWNERS`; tanpa tim ini CODEOWNERS tidak berfungsi
- [ ] Tentukan apakah Discussions diaktifkan
- [ ] Tentukan kanal kontak resmi yang dirujuk `SECURITY.md` dan `LICENSE`

---

## C. Yang sengaja tidak dilakukan

**Workflow rilis otomatis belum dibuat.** Repository belum punya strategi
versioning maupun rilis bertag. Membuat pipeline rilis sebelum strategi itu ada
hanya menghasilkan otomatisasi yang tidak dipakai. Lihat `ROADMAP.md`.
