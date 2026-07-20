<?php

namespace App\Domain\Customers\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Penyalur cadangan saat belum ada penyedia WhatsApp yang dikonfigurasi.
 *
 * Kodenya ditulis ke log, BUKAN ditampilkan di layar: menampilkannya berarti
 * siapa pun yang memegang HP orang lain bisa masuk tanpa perlu WhatsApp sama
 * sekali, dan itu menghapus seluruh gunanya OTP. Log hanya bisa dibaca orang
 * yang sudah punya akses ke server.
 *
 * Sengaja MENOLAK bekerja di produksi — kios yang mengirim kode ke log adalah
 * kios yang tidak bisa dipakai siapa pun, dan itu harus ketahuan saat memasang,
 * bukan saat pelanggan pertama berdiri di depan TV.
 */
class LoggingOtpChannel implements OtpChannel
{
    public function send(string $phone, string $code): bool
    {
        if (app()->isProduction()) {
            Log::error('OTP tidak terkirim: penyedia WhatsApp belum dikonfigurasi.', ['phone' => $phone]);

            return false;
        }

        Log::info('OTP (mode pengembangan)', ['phone' => $phone, 'code' => $code]);

        return true;
    }

    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return ! app()->isProduction();
    }
}
