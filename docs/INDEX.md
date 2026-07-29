# Indeks Dokumentasi

Navigasi berdasarkan peran. Setiap tautan menunjuk dokumen yang **benar-benar
ada**; yang belum ditulis ditandai terbuka sebagai `Belum ditulis` agar tidak
ada tautan mati.

---

## Owner outlet

| Kebutuhan | Dokumen |
|-----------|---------|
| Gambaran sistem & kemampuannya | [README](../README.md#kemampuan-utama) |
| Kesiapan sistem apa adanya | [Repository Audit](audits/REPOSITORY_AUDIT.md#a-executive-summary) |
| Monitoring & dashboard | [Legacy README](LEGACY_README.md) — bagian monitoring |
| Backup & pemulihan | [Legacy README](LEGACY_README.md) — **restore belum teruji**, lihat [risiko](audits/REPOSITORY_AUDIT.md#h-risiko-yang-masih-terbuka) |
| Rencana pengembangan | [Roadmap](../ROADMAP.md) |

## Kasir

| Kebutuhan | Dokumen |
|-----------|---------|
| Operasi harian & manajemen sesi | [Legacy README](LEGACY_README.md) |
| Penanganan pembayaran | [Legacy README](LEGACY_README.md) |
| Peringatan perangkat | [Legacy README](LEGACY_README.md) |
| Melaporkan masalah | [Support](../SUPPORT.md) |

## Teknisi lapangan

| Kebutuhan | Dokumen |
|-----------|---------|
| Instalasi outlet | [README — Quick start](../README.md#quick-start) |
| Penyiapan perangkat & pairing TV | [Legacy README](LEGACY_README.md) |
| Diagnostik perangkat | `docker compose exec app php artisan tv:doctor` |
| Troubleshooting | [Legacy README](LEGACY_README.md) |

## Developer

| Kebutuhan | Dokumen |
|-----------|---------|
| Standar engineering & alur PR | [Contributing](../CONTRIBUTING.md) |
| Aturan database & migrasi | [Contributing §6](../CONTRIBUTING.md#6-aturan-database) |
| Aturan pengujian per jenis perubahan | [Contributing §7](../CONTRIBUTING.md#7-aturan-pengujian) |
| Menjalankan test dengan aman | [README — Pengujian](../README.md#pengujian) |
| Peta domain, model, dan perintah | [Repository Audit §B](audits/REPOSITORY_AUDIT.md#b-repository-inventory) |
| Arsitektur mendalam | [Legacy README](LEGACY_README.md) |

## DevOps / SRE

| Kebutuhan | Dokumen |
|-----------|---------|
| Deployment Docker | [README — Quick start](../README.md#quick-start) |
| Checklist produksi | [README — Deployment produksi](../README.md#deployment-produksi) |
| Pipeline CI | [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) |
| Celah operasional yang diketahui | [Repository Audit §F](audits/REPOSITORY_AUDIT.md#f-operational-gap) |
| Konfigurasi GitHub yang harus diatur manual | [GitHub Configuration](GITHUB_CONFIGURATION.md) |
| Runbook insiden | `operations/INCIDENT_RESPONSE.md` — **Belum ditulis** |
| Disaster recovery | `operations/DISASTER_RECOVERY.md` — **Belum ditulis** |

## Keamanan

| Kebutuhan | Dokumen |
|-----------|---------|
| Melaporkan kerentanan | [Security Policy](../SECURITY.md) |
| Kontrol yang berlaku & buktinya | [Security Policy](../SECURITY.md#kontrol-yang-sudah-berlaku) |
| Celah keamanan hasil audit | [Repository Audit §E](audits/REPOSITORY_AUDIT.md#e-security-gap) |
| Threat model | `security/THREAT_MODEL.md` — **Belum ditulis** |

---

## Status ekstraksi dokumentasi

README lama (1.722 baris) dipindahkan utuh ke
[`LEGACY_README.md`](LEGACY_README.md) — **tidak ada isi yang hilang**.
Pemecahannya ke dokumen bertema dilakukan bertahap, bukan sekaligus, agar setiap
bagian bisa diverifikasi ulang terhadap source code saat dipindahkan.

| Direktori | Status |
|-----------|--------|
| `audits/` | Terisi — audit repository lengkap |
| `architecture/` | Kosong — sumber materi ada di `LEGACY_README.md` |
| `database/` | Kosong |
| `deployment/` | Kosong |
| `operations/` | Kosong |
| `security/` | Kosong — kebijakan ada di `SECURITY.md` |
| `testing/` | Kosong — aturan ada di `CONTRIBUTING.md §7` |
| `decisions/` | Kosong — ADR belum ditulis |
| `releases/` | Kosong |

Direktori kosong sengaja **tidak** diisi berkas placeholder. Dokumen yang ada
judulnya tetapi kosong isinya lebih menyesatkan daripada tidak ada sama sekali.
