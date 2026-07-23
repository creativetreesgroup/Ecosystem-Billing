# Changelog

Semua perubahan berarti pada sistem ini dicatat di sini.

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id/1.1.0/).
Berkas ini adalah sumber tunggal — halaman **Changelog** di panel membacanya
langsung, jadi tidak ada salinan yang bisa basi.

## [Belum dirilis]

### Ditambahkan

- Halaman **Changelog** di panel: membaca berkas ini secara langsung, jadi
  perubahan yang Anda baca di panel selalu sama dengan yang benar-benar dirilis.
- Halaman **Aktivitas** berbentuk lini masa, terpisah antara pengguna internal
  (staf outlet) dan eksternal (pelanggan kios).
- **Peran & izin lewat Filament Shield**, ditata per departemen. Setiap peran
  punya alamat: departemen mana, dan tingkat apa di dalamnya. Admin departemen
  memegang seluruh izin grupnya — termasuk izin resource yang baru ditambahkan
  belakangan, tanpa perlu dicentang ulang.
- Empat aksi non-CRUD menjadi izin tersendiri: void sesi, verifikasi
  pembayaran, penyesuaian saldo, dan penanganan alert perangkat.
- **Jam layanan dapur**: jam buka, jam tutup, jeda istirahat, dan saklar utama.
  Kios menampilkan pemberitahuan beserta jam kembalinya, bukan sekadar menu
  kosong.
- **Pengembalian saldo otomatis** saat layanan terbukti gagal: TV yang tak
  pernah menyala membatalkan sesinya dan mengembalikan uangnya; pesanan makanan
  yang tak pernah disentuh staf dikembalikan setelah batas waktu.
- `wallet:audit` — rekonsiliasi harian saldo terhadap buku besarnya.
- **Pemesanan makanan & minuman** dari kios, dibayar dari saldo.
- **Biaya admin** untuk isi saldo QRIS & transfer, bisa disetel atau dimatikan.

### Diubah

- PIN dimasukkan lewat enam kotak angka seperti kode OTP, dan tetap disamarkan.
- Penamaan berkas diseragamkan: command tanpa akhiran `Command`, job dengan
  akhiran `Job`, dan `app/Models` kini hanya berisi model Eloquent.

### Diperbaiki

- Layar "Pembayaran berhasil" sempat melaporkan total kotor sebagai kenaikan
  saldo, sehingga isi saldo Rp 50.000 dengan biaya Rp 2.500 tertulis "saldo
  bertambah Rp 52.500". Kini yang ditampilkan adalah yang benar-benar masuk.
- Membatalkan QRIS yang ternyata sudah dibayar tidak lagi menghanguskannya:
  gateway ditanya lebih dulu, dan bila sudah lunas saldonya dikreditkan.
- Suite pengujian tidak lagi mati di tengah jalan (batas memori PHP).
- nginx tidak lagi 502 setelah container dibangun ulang.

### Keamanan

- Invarian uang dikunci di kode dan **tidak bisa dibuka oleh centang izin mana
  pun, termasuk super admin**: pembayaran tidak bisa diketik manual maupun
  dihapus, dan pengaturan tidak bisa dihapus. Pemasukan harus selalu punya sesi
  yang menjelaskannya, dan jejak sengketa nominal tidak boleh hilang.
- Generator policy Shield dimatikan agar tidak menimpa aturan di atas dengan
  pemeriksaan izin polos.
