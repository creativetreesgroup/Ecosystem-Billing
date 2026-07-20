<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sessions:sweep-expired')->everyMinute()->withoutOverlapping();

// §7 minta polling tiap 45s; Laravel tidak punya preset itu (hanya kelipatan
// 5/10/15/20/30s), jadi dibulatkan ke preset terdekat — lihat DECISIONS.md.
Schedule::command('units:poll-state')->everyThirtySeconds()->withoutOverlapping()->runInBackground();

// Pengganti webhook Midtrans. Mesin outlet tidak boleh menerima koneksi dari
// internet (§14), jadi statusnya ditanyakan keluar. Setiap menit sudah cukup:
// pelanggan melihat pembayarannya diakui dalam hitungan detik, dan gateway
// tidak dibebani permintaan tanpa henti.
Schedule::command('payments:poll-qris')->everyMinute()->withoutOverlapping();
