# Dukungan

## Untuk operator outlet (owner, kasir, teknisi)

Dukungan operasional diberikan lewat kanal internal Creative Trees Group, bukan
lewat GitHub. GitHub Issues digunakan untuk pelacakan teknis, bukan sebagai
helpdesk.

Sebelum melapor, kumpulkan:

- Apa yang terjadi dan kapan
- Unit atau outlet yang terpengaruh
- Apakah ada uang atau saldo yang terlibat
- Keluaran `docker compose ps` dan `docker compose logs app --tail=100`

## Untuk engineer

| Kebutuhan | Kanal |
|-----------|-------|
| Bug | [Issue: laporan bug](https://github.com/creativetreesgroup/Ecosystem-Billing/issues/new?template=bug_report.yml) |
| Usulan fitur | [Issue: usulan fitur](https://github.com/creativetreesgroup/Ecosystem-Billing/issues/new?template=feature_request.yml) |
| Kerentanan keamanan | [SECURITY.md](SECURITY.md) — kanal privat |
| Pertanyaan teknis | Issue dengan label `question` |

## Yang tidak didukung

- Menjalankan sistem ini di luar perjanjian dengan Creative Trees Group
  (lihat [`LICENSE`](LICENSE))
- Modifikasi pihak ketiga
- Integrasi perangkat keras di luar yang terdokumentasi

## Waktu tanggapan

Repository ini dipelihara oleh tim internal dengan prioritas operasional
outlet lebih dulu. Tidak ada SLA untuk issue publik. Laporan yang menyangkut
kehilangan uang atau data ditangani paling dulu.
