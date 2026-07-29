<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien tipis WAHA (WhatsApp HTTP API) — satu tempat pola pengirimannya.
 *
 * Dipakai OTP masuk (WahaOtpChannel) maupun notifikasi ke pelanggan
 * (CustomerNotifier). WAHA berjalan di LAN outlet (§14: mesin outlet tidak
 * menerima koneksi internet — panggilan ini KELUAR ke alamat LAN, seperti poll
 * Midtrans). API key hanya dari .env, dikirim di header X-Api-Key. Isi pesan
 * TIDAK PERNAH ikut ke log — kegagalan hanya mencatat nomor & status.
 */
class WahaClient
{
    /**
     * Kirim satu pesan teks. Nomor lokal 08xxxxxxxxx diubah ke chatId
     * internasional <62...>@c.us. Tidak melempar: gagal kirim adalah keadaan
     * normal yang ditangani pemanggil (mis. OTP jatuh ke PIN).
     */
    public function sendText(string $phone, string $message): bool
    {
        $response = Http::withHeaders(['X-Api-Key' => (string) config('services.waha.api_key')])
            ->timeout(10)
            ->retry(2, 200, throw: false)
            ->post(rtrim((string) config('services.waha.base_url'), '/').'/api/sendText', [
                'session' => (string) config('services.waha.session', 'default'),
                'chatId' => '62'.substr($phone, 1).'@c.us',
                'text' => $message,
            ]);

        if (! $response->successful()) {
            Log::warning('WAHA gagal mengirim pesan.', ['phone' => $phone, 'status' => $response->status()]);

            return false;
        }

        return true;
    }

    public function isConfigured(): bool
    {
        return filled(config('services.waha.base_url')) && filled(config('services.waha.api_key'));
    }
}
