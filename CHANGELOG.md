# Changelog

Catatan perubahan sistem billing Creative Trees.

Berkas ini adalah sumber tunggal: halaman **Changelog** di panel membacanya
langsung, jadi yang dibaca staf selalu sama dengan yang benar-benar dirilis.

Format mengikuti [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) dan
penomoran mengikuti [Semantic Versioning](https://semver.org/lang/id/).

> Judul bagian (`Added`, `Changed`, `Fixed`, `Security`) sengaja tetap dalam
> bahasa Inggris — itu kosakata baku Keep a Changelog dan yang dikenali
> pembaca changelog mana pun, termasuk halaman di panel. Isinya tetap bahasa
> Indonesia.

## [0.6.0] - 2026-07-23

### Added

- **Peran & izin lewat Filament Shield, ditata per departemen.** Setiap peran
  kini punya alamat: departemen mana (Keuangan, Operasional, Maintenance, Data
  Master, Sistem) dan tingkat apa di dalamnya. Admin departemen memegang
  seluruh izin grupnya — termasuk izin resource yang ditambahkan belakangan,
  tanpa perlu dicentang ulang.
- Empat aksi non-CRUD menjadi izin tersendiri, bukan menumpang izin lain:
  void sesi, verifikasi pembayaran, penyesuaian saldo, dan penanganan alert
  perangkat.
- Halaman **Aktivitas** berbentuk lini masa, dengan pemisahan pengguna internal
  (staf outlet) dan eksternal (pelanggan kios).
- Halaman **Changelog** di panel, membaca berkas ini secara langsung.

### Changed

- PIN kios dimasukkan lewat enam kotak angka seperti kode OTP, dan tetap
  disamarkan — bentuknya sama, isinya tidak: PIN dipakai berulang, kode OTP
  tidak.
- Penamaan berkas diseragamkan: command tanpa akhiran `Command`, job dengan
  akhiran `Job`, dan `app/Models` kini hanya berisi model Eloquent.

### Fixed

- Laporan Penjualan dan aksi massal unit masih dijaga oleh kolom peran lama,
  sehingga izin yang diberikan lewat panel tidak berpengaruh apa pun.
- Notifikasi utang pelanggan tidak pernah sampai ke peran Keuangan yang dibuat
  lewat panel; kini dikirim ke pemegang izin pembayaran.
- `app:create-owner` tidak memberi peran Shield apa pun, sehingga instalasi baru
  menghasilkan owner yang bisa masuk panel tapi tidak melihat apa-apa.

### Security

- Invarian uang dikunci di kode dan **tidak bisa dibuka oleh centang izin mana
  pun, termasuk super admin**: pembayaran tidak bisa diketik manual maupun
  dihapus, dan pengaturan tidak bisa dihapus. Pemasukan harus selalu punya sesi
  yang menjelaskannya, dan jejak sengketa nominal tidak boleh hilang.
- Generator policy Shield dimatikan agar tidak menimpa aturan di atas dengan
  pemeriksaan izin polos.

## [0.5.0] - 2026-07-22

### Added

- **Pemesanan makanan & minuman dari kios**, lengkap dengan kategori, dibayar
  dari saldo pelanggan.
- **Jam layanan dapur**: jam buka, jam tutup, jeda istirahat, dan saklar utama.
  Saat tutup, kios memberi tahu beserta jam kembalinya — bukan sekadar
  menampilkan menu kosong.
- **Pengembalian saldo otomatis saat layanan terbukti gagal**: TV yang terbukti
  tidak menyala membatalkan sesinya dan mengembalikan uangnya; pesanan makanan
  yang tak pernah disentuh staf dikembalikan setelah batas waktu.
- `wallet:audit` — rekonsiliasi harian saldo terhadap buku besarnya.
- **Biaya admin** untuk isi saldo QRIS & transfer, bisa disetel atau dimatikan.

### Fixed

- Layar "Pembayaran berhasil" melaporkan total kotor sebagai kenaikan saldo,
  sehingga isi saldo Rp 50.000 dengan biaya Rp 2.500 tertulis "saldo bertambah
  Rp 52.500". Kini yang ditampilkan adalah yang benar-benar masuk.
- Suite pengujian tidak lagi mati di tengah jalan (batas memori PHP).

## [0.4.0] - 2026-07-21

### Added

- **Voucher & promo otomatis** untuk paket, open play, dan bonus isi saldo.
- Notifikasi WhatsApp ke pelanggan: sesi hampir habis, ringkasan sesi, dan
  konfirmasi isi saldo.

### Fixed

- Bonus isi saldo tidak lagi dijepit ke nominal top-up, sehingga promo "isi
  20rb bonus 25rb" berjalan sebagaimana dimaksud.
- Void sesi membebaskan kembali kuota voucher yang terpakai.

## [0.3.0] - 2026-07-20

### Added

- **Dompet pelanggan**: isi saldo lewat QRIS/transfer/tunai, lalu main tanpa
  kasir sama sekali.
- Masuk kios dengan OTP WhatsApp, dengan PIN sebagai jalur cadangan.
- Jaring pengaman rekonsiliasi untuk pembayaran yang lunas tapi efeknya belum
  berjalan.

### Fixed

- Perpanjangan sesi yang dibayar dari dompet kini benar-benar memotong saldo.

## [0.2.0] - 2026-07-19

### Added

- **Kontrol TV lewat jaringan** (Home Assistant & Tasmota) beserta verifikasi
  bahwa TV benar-benar menyala, karena jawaban sukses dari gateway tidak
  membuktikan apa pun.
- Sesi paket dan open play, penyelesaian otomatis saat waktu habis, serta
  plafon kredit open play.
- Dasbor kasir, laporan penjualan, dan alert perangkat.

## [0.1.0] - 2026-07-19

### Added

- Fondasi: outlet, unit, tipe unit, paket, pelanggan, dan panel Filament.

[0.6.0]: https://github.com/
[0.5.0]: https://github.com/
[0.4.0]: https://github.com/
[0.3.0]: https://github.com/
[0.2.0]: https://github.com/
[0.1.0]: https://github.com/
