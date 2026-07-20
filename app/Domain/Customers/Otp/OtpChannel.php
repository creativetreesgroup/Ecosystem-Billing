<?php

namespace App\Domain\Customers\Otp;

/**
 * Pengirim kode. Kontrak sengaja setipis mungkin — satu nomor, satu kode —
 * supaya mengganti penyedia (Meta Cloud API, gateway lokal, atau SMS saat
 * WhatsApp bermasalah) tidak menyentuh satu pun aturan keamanan di atasnya.
 */
interface OtpChannel
{
    /**
     * @return bool berhasil terkirim atau tidak. TIDAK melempar: kegagalan
     *              pengiriman adalah keadaan normal yang harus ditangani alur,
     *              bukan kejadian luar biasa.
     */
    public function send(string $phone, string $code): bool;

    public function name(): string;

    public function isConfigured(): bool;
}
