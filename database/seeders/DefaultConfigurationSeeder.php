<?php

namespace Database\Seeders;

use App\Domain\Devices\IntegrationKey;
use App\Domain\Settings\SettingKey;
use App\Models\Integration;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Mewujudkan konfigurasi bawaan menjadi baris yang benar-benar ada.
 *
 * Ini bukan seeder demo dan tidak membuat data karangan: nilainya diambil dari
 * SettingKey::default(), sumber yang sama yang sudah dipakai aplikasi saat
 * berjalan. Yang berubah hanya keterlihatannya.
 *
 * Kenapa perlu. Setting::get() jatuh ke default() bila barisnya tidak ada,
 * jadi aplikasi berjalan benar dengan tabel kosong — tetapi panel menampilkan
 * baris database, sehingga owner membuka layar Pengaturan dan Integrasi yang
 * KOSONG. Ia tidak punya cara mengetahui bahwa tarif dibulatkan per 1 menit,
 * peringatan dikirim 5 menit sebelum waktu habis, atau biaya admin top-up
 * Rp 2.500 sedang berlaku. Nilai yang mengatur uang tetapi tidak terlihat oleh
 * siapa pun adalah nilai yang tidak bisa dipertanggungjawabkan.
 *
 * TIDAK memakai Setting::put(). Method itu ber-updateOrCreate, dan seeder ini
 * dijalankan entrypoint SETIAP kali container app menyala — memakai put()
 * berarti tarif, biaya admin, dan jam buka yang sudah disesuaikan owner
 * direset ke bawaan pada setiap restart, diam-diam, tanpa ada yang menyadari
 * sampai ada pelanggan tertagih dengan angka yang salah.
 */
class DefaultConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        // Semua kunci yang dikenal sistem dibuat sekaligus. Menambah pengaturan
        // baru cukup menambah case di SettingKey — seeder ini tidak perlu
        // disentuh, dan tidak ada lagi kunci yang "ada di kode tetapi tidak ada
        // barisnya di database".
        foreach (SettingKey::cases() as $key) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => ['value' => $key->default()]],
            );
        }

        // Barisnya dibuat KOSONG, bukan diisi contoh: sampai pemilik menempel
        // tokennya sendiri dari Home Assistant, sistem tetap memakai .env.
        // Baris berisi token palsu justru akan menang atas .env dan membuat
        // integrasi yang tadinya jalan mendadak gagal otentikasi.
        //
        // Tanpa base_url dan token, Integration::isUsable() tetap false, jadi
        // kontrol perangkat tetap mati sampai benar-benar dikonfigurasi.
        foreach (IntegrationKey::cases() as $integration) {
            Integration::query()->firstOrCreate(
                ['key' => $integration],
                [
                    'base_url' => null,
                    'token' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
