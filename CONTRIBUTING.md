# Panduan Kontribusi

Repository ini berlisensi **proprietary** (lihat [`LICENSE`](LICENSE)). Source
code dapat dilihat publik untuk transparansi dan audit, tetapi pengembangan
dilakukan oleh tim engineering internal Creative Trees Group.

**Kontribusi eksternal:** laporan bug dan usulan fitur diterima lewat Issues.
Pull request dari luar organisasi hanya diterima bila sudah disepakati lebih
dulu di sebuah issue — perubahan pada sistem yang menangani uang tidak bisa
diterima tanpa konteks.

---

## 1. Menyiapkan lingkungan lokal

Sistem ini dijalankan di Docker. Jalur non-Docker hanya untuk pengembangan.

```bash
git clone https://github.com/creativetreesgroup/Ecosystem-Billing.git
cd Ecosystem-Billing
./install.sh
```

Instalasi bersifat idempoten: kunci dan password yang sudah terisi tidak
ditimpa. Detail: [`docs/audits/REPOSITORY_AUDIT.md`](docs/audits/REPOSITORY_AUDIT.md).

## 2. Menjalankan test

```bash
docker compose --profile test run --rm test php artisan test --compact
```

> **Jangan pernah `docker compose exec app php artisan test`.** Service `app`
> membaca `env_file: .env`, sehingga test akan memakai `APP_ENV=production`
> **dan database produksi**; `RefreshDatabase` lalu menjalankan `migrate:fresh`
> — `DROP` seluruh tabel. Service `test` ada justru untuk mencegah ini: ia
> sengaja tidak membaca `.env`.

## 3. Penamaan branch

```
feat/<ringkas>      fitur baru
fix/<ringkas>       perbaikan bug
refactor/<ringkas>  perubahan struktur tanpa perubahan perilaku
docs/<ringkas>      dokumentasi saja
chore/<ringkas>     perkakas, dependency, CI
```

## 4. Pesan commit

Satu commit menjelaskan satu perubahan, dalam bentuk kalimat yang menyatakan
akibatnya bagi sistem — bukan daftar berkas yang disentuh.

```
fix(billing): sesi tidak lagi bisa dimulai dua kali pada unit yang sama
feat(discounts): kode voucher dibuat sistem, bukan diketik manual
```

## 5. Aturan Pull Request

- **Satu PR satu tujuan.** Jangan mencampur refactor besar dengan fitur.
- Isi seluruh [template PR](.github/PULL_REQUEST_TEMPLATE.md). Checklist yang
  dicentang tanpa dikerjakan lebih buruk daripada checklist kosong.
- CI wajib hijau. PR dengan CI merah tidak direview.
- Sertakan hasil perintah yang dijalankan, bukan pernyataan "sudah dites".

## 6. Aturan database

- Setiap perubahan skema lewat migrasi. Tidak ada perubahan manual di produksi.
- Migrasi harus aman dijalankan pada database yang sudah berisi data outlet.
- Kolom nilai uang **wajib** bertipe integer. Tidak ada `float`, `double`, atau
  `decimal` untuk uang — pembulatan biner tidak bisa dipertanggungjawabkan ke
  pelanggan.
- Migrasi yang menghapus kolom atau tabel harus dipisah dari PR yang berhenti
  memakainya, agar rollback tetap mungkin.

## 7. Aturan pengujian

| Jenis perubahan | Test yang wajib menyertainya |
|-----------------|------------------------------|
| Perhitungan billing | Test unit yang gagal tanpa perubahan tersebut |
| Pembayaran | Test idempotensi — pembayaran yang sama tidak boleh dikredit dua kali |
| Saldo pelanggan | Test di `tests/Concurrency/` dengan proses anak sungguhan |
| Kontrol perangkat | Test jalur gagal, bukan hanya jalur sukses |
| Perbaikan bug | Test yang mereproduksi bug, ditulis sebelum perbaikannya |

## 8. Aturan keamanan

- Jangan pernah meng-commit `.env`, backup `.env`, token, atau dump database.
- Jangan menaruh secret di test, seed, atau fixture — gunakan nilai sintetis.
- Kerentanan dilaporkan lewat [`SECURITY.md`](SECURITY.md), bukan public issue.

## 9. Gaya kode

```bash
vendor/bin/pint --dirty
```

Konvensi mengikuti berkas di sekitarnya. Komentar menjelaskan **mengapa**, bukan
mengulang **apa** yang sudah jelas dari kodenya.

## 10. Checklist review

Reviewer memeriksa, berurutan:

1. Apakah perubahan ini bisa menghilangkan atau menggandakan uang?
2. Apakah ada jalur gagal yang tidak tertangani?
3. Apakah migrasinya aman di database yang sedang dipakai?
4. Apakah ada secret yang bocor?
5. Apakah testnya benar-benar gagal tanpa perubahan ini?
6. Apakah dokumentasinya masih benar setelah perubahan ini?
