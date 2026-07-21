<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sessions:sweep-expired')->everyMinute()->withoutOverlapping();

// Jaring pengaman plafon kredit Open Play: menghentikan sesi kios yang HP-nya
// ditutup sebelum tagihannya melewati −50k jadi kerugian outlet. Tiap menit
// sudah cukup rapat — plafon terlampaui paling banyak ~1 menit, dan penutupannya
// tetap dijepit di plafon oleh StopKioskOpenPlayAction.
Schedule::command('openplay:enforce-ceiling')->everyMinute()->withoutOverlapping();

// §7 minta polling tiap 45s; Laravel tidak punya preset itu (hanya kelipatan
// 5/10/15/20/30s), jadi dibulatkan ke preset terdekat — lihat DECISIONS.md.
Schedule::command('units:poll-state')->everyThirtySeconds()->withoutOverlapping()->runInBackground();

// Pengganti webhook Midtrans. Mesin outlet tidak boleh menerima koneksi dari
// internet (§14), jadi statusnya ditanyakan KELUAR — dan seberapa sering itulah
// yang menentukan seberapa cepat "Pembayaran berhasil" muncul. everyMinute()
// dulu berarti pelanggan menunggu SAMPAI 60 detik di depan QR yang sudah
// dibayar; everyTenSeconds() memangkasnya ke ≤10 detik. Yang dipoll hanya QRIS
// yang masih menggantung (biasanya nol), jadi gateway tidak terbebani.
// withoutOverlapping() mencegah penumpukan bila satu poll melambat.
Schedule::command('payments:poll-qris')->everyTenSeconds()->withoutOverlapping();
