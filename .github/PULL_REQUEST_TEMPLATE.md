## Ringkasan

<!-- Apa yang berubah, dalam 1-3 kalimat. -->

## Dampak bisnis

<!-- Siapa yang terpengaruh: owner, kasir, pelanggan, teknisi? Kalau tidak ada, tulis "tidak ada". -->

## Perubahan teknis

## Perubahan database

<!-- Migrasi baru? Reversible? Aman dijalankan di outlet yang sedang beroperasi? Kalau tidak ada, tulis "tidak ada". -->

## Dampak keamanan

<!-- Menyentuh otorisasi, uang, secret, atau data pelanggan? Kalau tidak, tulis "tidak ada". -->

## Dampak performa

## Perubahan dokumentasi

## Pengujian yang dilakukan

<!-- Perintah yang dijalankan dan hasilnya. Bukan "sudah dites". -->

---

### Checklist wajib

- [ ] `docker compose --profile test run --rm test php artisan test --compact` lulus
- [ ] `vendor/bin/pint --test` lulus
- [ ] Tidak ada secret, token, atau `.env` dalam diff
- [ ] Migrasi aman dijalankan pada database yang sudah berisi data
- [ ] Rollback sudah dipikirkan dan ditulis di atas
- [ ] Dokumentasi terkait sudah diperbarui
- [ ] Tidak ada perubahan yang tidak berhubungan dengan tujuan PR ini

### Checklist bersyarat

- [ ] Perubahan **billing** disertai test yang gagal tanpa perubahan ini
- [ ] Perubahan **pembayaran** disertai test idempotensi
- [ ] Perubahan **saldo** disertai test concurrency di `tests/Concurrency/`
- [ ] Perubahan **kontrol perangkat** disertai test jalur gagal, bukan hanya jalur sukses
