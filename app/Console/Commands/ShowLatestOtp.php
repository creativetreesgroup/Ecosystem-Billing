<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Membaca kode OTP terakhir dari log — hanya di luar produksi.
 *
 * Saat penyedia WhatsApp belum dipasang, kodenya ditulis ke log dan bukan ke
 * layar (menampilkannya di layar berarti siapa pun yang memegang HP orang lain
 * bisa masuk, dan itu menghapus seluruh guna OTP). Tapi menggerayangi
 * laravel.log dengan grep setiap kali menguji kios bukan pekerjaan yang wajar,
 * dan kelelahan itulah yang biasanya berakhir dengan seseorang "sementara"
 * menampilkan kode di layar.
 *
 * MENOLAK jalan di produksi: di sana kode tidak pernah ada di log, dan perintah
 * yang seolah bisa menunjukkannya hanya mengundang percobaan.
 */
#[Signature('otp:latest {phone? : Batasi ke satu nomor}')]
#[Description('Tampilkan kode OTP terakhir dari log (hanya di luar produksi)')]
class ShowLatestOtp extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Tidak tersedia di produksi. Di sana kode dikirim lewat WhatsApp, tidak pernah ditulis ke log.');

            return self::FAILURE;
        }

        $log = storage_path('logs/laravel.log');

        if (! is_readable($log)) {
            $this->error('Berkas log belum ada. Minta kode dulu dari kios.');

            return self::FAILURE;
        }

        $phone = $this->argument('phone');

        // Dibaca dari BELAKANG: yang dicari selalu yang terakhir, dan log
        // produksi-lama bisa berukuran ratusan megabita.
        $found = [];

        foreach (array_reverse(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            if (! str_contains($line, 'OTP (mode pengembangan)')) {
                continue;
            }

            if (! preg_match('/"phone":"([^"]+)".*"code":"(\d+)"/', $line, $m)) {
                continue;
            }

            if ($phone && $m[1] !== $phone) {
                continue;
            }

            $found[] = ['nomor' => $m[1], 'kode' => $m[2]];

            if (count($found) >= 5) {
                break;
            }
        }

        if ($found === []) {
            $this->warn($phone
                ? "Belum ada kode untuk {$phone}."
                : 'Belum ada kode di log. Minta kode dulu dari layar kios.');

            return self::SUCCESS;
        }

        $this->table(['Nomor', 'Kode'], $found);
        $this->line('Terbaru di baris pertama. Kode kedaluwarsa mengikuti masa berlaku OTP.');

        return self::SUCCESS;
    }
}
