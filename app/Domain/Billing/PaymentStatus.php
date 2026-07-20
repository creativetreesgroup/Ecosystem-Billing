<?php

namespace App\Domain\Billing;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Perjalanan satu pembayaran, dari ditagih sampai benar-benar diterima.
 *
 * Sebelum ini, "sudah bayar" hanyalah kolom payment_method yang terisi —
 * cukup selama kasir yang memegang uangnya di depan, karena kasirlah
 * buktinya. Begitu pelanggan bisa membayar sendiri lewat QR, kolom terisi
 * tidak lagi berarti uangnya masuk: QRIS bisa dibatalkan di tengah, dan
 * bukti transfer bisa palsu atau salah nominal.
 *
 * Yang boleh dihitung sebagai pendapatan HANYA Paid.
 */
enum PaymentStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case AwaitingVerification = 'awaiting_verification';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu pembayaran',
            self::AwaitingVerification => 'Menunggu verifikasi kasir',
            self::Paid => 'Lunas',
            self::Rejected => 'Bukti ditolak',
            self::Expired => 'Kedaluwarsa',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::AwaitingVerification => 'warning',
            self::Pending => 'info',
            self::Rejected, self::Expired => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Paid => Heroicon::OutlinedCheckCircle,
            self::AwaitingVerification => Heroicon::OutlinedClock,
            self::Pending => Heroicon::OutlinedQrCode,
            self::Rejected => Heroicon::OutlinedXCircle,
            self::Expired => Heroicon::OutlinedExclamationTriangle,
        };
    }

    /**
     * Hanya ini yang boleh masuk laporan pendapatan.
     */
    public function isSettled(): bool
    {
        return $this === self::Paid;
    }
}
