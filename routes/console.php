<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sessions:sweep-expired')->everyMinute()->withoutOverlapping();

// Jaring pengaman plafon kredit Open Play: menghentikan sesi kios yang HP-nya
// ditutup sebelum tagihannya melewati −50k jadi kerugian outlet. Tiap menit
// sudah cukup rapat — plafon terlampaui paling banyak ~1 menit, dan penutupannya
// tetap dijepit di plafon oleh StopKioskOpenPlayAction.
Schedule::command('openplay:enforce-ceiling')->everyMinute()->withoutOverlapping();

// §7 minta polling tiap 45s; Laravel tidak punya preset itu (hanya kelipatan
// 5/10/15/20/30s), jadi dibulatkan ke preset terdekat — lihat README.md.
Schedule::command('units:poll-state')->everyThirtySeconds()->withoutOverlapping()->runInBackground();

// Pengganti webhook Midtrans. Mesin outlet tidak boleh menerima koneksi dari
// internet (§14), jadi statusnya ditanyakan KELUAR — dan seberapa sering itulah
// yang menentukan seberapa cepat "Pembayaran berhasil" muncul. everyMinute()
// dulu berarti pelanggan menunggu SAMPAI 60 detik di depan QR yang sudah
// dibayar; everyTenSeconds() memangkasnya ke ≤10 detik. Yang dipoll hanya QRIS
// yang masih menggantung (biasanya nol), jadi gateway tidak terbebani.
// withoutOverlapping() mencegah penumpukan bila satu poll melambat.
Schedule::command('payments:poll-qris')->everyTenSeconds()->withoutOverlapping();

// Jaring pengaman "sudah bayar, makanannya tidak pernah datang": pesanan yang
// tak pernah disentuh staf dibatalkan dan saldonya dikembalikan. Batas waktunya
// disetel di Pengaturan (default 60 menit), jadi cukup diperiksa tiap menit —
// yang menentukan cepat-lambatnya adalah batas itu, bukan frekuensi ini.
Schedule::command('menu:refund-stale-orders')->everyMinute()->withoutOverlapping();

// Rekonsiliasi harian: saldo vs buku besar. Tidak membetulkan apa pun, hanya
// berteriak — exit code bukan-nol supaya monitoring menangkapnya. Dijalankan
// saat outlet sepi karena memindai seluruh pelanggan.
Schedule::command('wallet:audit')->dailyAt('04:00')->withoutOverlapping();

// Jaring pengaman: pembayaran yang lunas tapi efeknya (kredit saldo / mulai
// sesi) gagal jalan setelah commit — poll tak akan mencobanya lagi karena
// statusnya bukan Pending. Tiap 5 menit sudah rapat; kejadiannya langka dan
// penerapannya idempoten. Lihat ReconcileSettledPayments.
Schedule::command('payments:reconcile-settled')->everyFiveMinutes()->withoutOverlapping();
