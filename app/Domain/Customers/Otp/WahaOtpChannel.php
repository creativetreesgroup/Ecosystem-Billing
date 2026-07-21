<?php

namespace App\Domain\Customers\Otp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim OTP lewat WAHA (WhatsApp HTTP API) — dipakai di produksi.
 *
 * WAHA dijalankan di LAN outlet (§14: mesin outlet tidak menerima koneksi
 * internet — panggilan ke sini KELUAR ke alamat LAN, sama seperti poll Midtrans).
 * API key dikirim di header X-Api-Key dan hanya hidup di .env. Kode OTP masuk ke
 * BADAN request (memang itu pesannya ke pelanggan) tapi TIDAK PERNAH ikut ke log
 * — kegagalan hanya mencatat nomor & status.
 */
class WahaOtpChannel implements OtpChannel
{
    public function send(string $phone, string $code): bool
    {
        // CustomerPhone::normalise menghasilkan 08xxxxxxxxx; WAHA butuh chatId
        // internasional <62...>@c.us.
        $chatId = '62'.substr($phone, 1).'@c.us';

        $response = Http::withHeaders(['X-Api-Key' => (string) config('services.waha.api_key')])
            ->timeout(10)
            ->retry(2, 200, throw: false)
            ->post(rtrim((string) config('services.waha.base_url'), '/').'/api/sendText', [
                'session' => (string) config('services.waha.session', 'default'),
                'chatId' => $chatId,
                'text' => "Kode masuk Creative Trees: {$code}\nBerlaku singkat. Jangan bagikan ke siapa pun.",
            ]);

        if (! $response->successful()) {
            Log::warning('OTP WAHA gagal terkirim.', ['phone' => $phone, 'status' => $response->status()]);

            return false;
        }

        return true;
    }

    public function name(): string
    {
        return 'waha';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.waha.base_url')) && filled(config('services.waha.api_key'));
    }
}
