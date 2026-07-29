# Roadmap

Status yang dipakai: `Planned`, `In progress`, `Under evaluation`, `Completed`.
Item di sini adalah rencana kerja, **bukan janji rilis**.

Prioritas ditentukan oleh urutan: integritas data → integritas uang → keamanan →
keandalan → pemulihan → observabilitas → kemudahan pemeliharaan.

---

## Now

| Item | Status | Alasan |
|------|--------|--------|
| Uji restore backup secara nyata | `Planned` | Risiko tertinggi yang tersisa. Prosedur pemulihan yang belum pernah diuji setara dengan tidak ada. |
| CI wajib hijau sebelum merge | `In progress` | Workflow sudah ada; branch protection perlu diaktifkan lewat UI GitHub |
| Pemecahan README ke `docs/` | `In progress` | README masih merangkap arsitektur, runbook, dan instalasi |

## Next

| Item | Status | Alasan |
|------|--------|--------|
| Aturan alert Prometheus | `Planned` | Metrik sudah dikumpulkan tetapi tidak ada yang memberi tahu saat rusak |
| Test end-to-end realtime (Reverb) | `Planned` | Jalur siaran ke browser belum terverifikasi otomatis |
| Heartbeat scheduler | `Planned` | Kegagalan `schedule:work` saat ini tidak terlihat |
| Strategi dead-letter queue | `Planned` | `--tries=3` ada, nasib job yang habis percobaan belum terdokumentasi |
| Test eskalasi peran | `Planned` | Policy ada, tetapi belum diuji dari sudut penyerang |
| Dokumentasi ADR | `Planned` | Keputusan besar belum tercatat alasannya |

## Later

| Item | Status |
|------|--------|
| Kebijakan rotasi log & pemantauan disk | `Planned` |
| Load testing pada jam sibuk outlet | `Planned` |
| Rate limiting terverifikasi | `Under evaluation` |
| Grafana di belakang reverse proxy | `Under evaluation` |

## Research

| Item | Status |
|------|--------|
| Webhook QRIS menggantikan polling | `Under evaluation` |
| Multi-outlet dalam satu instalasi | `Under evaluation` |
| Ketahanan operasi saat internet outlet putus | `Under evaluation` |

## Completed

| Item | Selesai |
|------|---------|
| Seluruh sistem berjalan di Docker | 2026-07-28 |
| Test suite berjalan aman di Docker (573 test) | 2026-07-28 |
| Isolasi database test dari database produksi | 2026-07-28 |
| Instalasi turnkey satu perintah dari volume kosong | 2026-07-28 |
| Ekstensi `sockets` untuk Wake-on-LAN | 2026-07-28 |
| Audit repository menyeluruh | 2026-07-28 |
| Endpoint `/health` dan `/ready` | 2026-07-28 |
