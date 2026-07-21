<?php

namespace App\Domain\Customers\Otp;

use App\Notifications\WahaClient;

/**
 * Pengirim OTP lewat WAHA (WhatsApp HTTP API) — dipakai di produksi.
 *
 * Pengirimannya lewat WahaClient bersama (satu tempat pola WAHA). Kode OTP masuk
 * ke pesan (memang itu isinya untuk pelanggan) tapi tidak pernah ikut ke log.
 */
class WahaOtpChannel implements OtpChannel
{
    public function __construct(private readonly WahaClient $waha) {}

    public function send(string $phone, string $code): bool
    {
        return $this->waha->sendText(
            $phone,
            "Kode masuk Creative Trees: {$code}\nBerlaku singkat. Jangan bagikan ke siapa pun.",
        );
    }

    public function name(): string
    {
        return 'waha';
    }

    public function isConfigured(): bool
    {
        return $this->waha->isConfigured();
    }
}
