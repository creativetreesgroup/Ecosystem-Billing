<?php

namespace App\Domain\Billing;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasColor, HasLabel
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Transfer = 'transfer';

    /**
     * Bayar dari saldo. TIDAK pernah dipilih kasir maupun pelanggan — hanya
     * diset PlayFromWalletAction. Uangnya sudah masuk laci saat isi saldo, jadi
     * ia dipisahkan dari tunai/QRIS/transfer supaya laporan laci tidak
     * menghitungnya sebagai uang baru.
     */
    case Wallet = 'wallet';

    /**
     * Metode yang boleh dipilih kasir saat membuka sesi. Wallet sengaja tidak
     * ada di sini: "bayar pakai saldo" adalah jalur kios (PlayFromWalletAction),
     * bukan sesuatu yang dibuka kasir.
     *
     * @return array<string, string>
     */
    public static function cashierOptions(): array
    {
        return collect(self::cashierMethods())
            ->mapWithKeys(fn (self $method) => [$method->value => $method->getLabel()])
            ->all();
    }

    /**
     * Warna per tombol untuk ToggleButtons kasir. Perlu ditulis eksplisit
     * karena begitu options() diberi array (bukan enum) untuk menyembunyikan
     * Wallet, Filament berhenti membaca getColor() dari enumnya.
     *
     * @return array<string, string>
     */
    public static function cashierColors(): array
    {
        return collect(self::cashierMethods())
            ->mapWithKeys(fn (self $method) => [$method->value => $method->getColor()])
            ->all();
    }

    /**
     * @return array<int, self>
     */
    private static function cashierMethods(): array
    {
        return [self::Cash, self::Qris, self::Transfer];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::Transfer => 'Transfer',
            self::Wallet => 'Saldo',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Qris => 'info',
            self::Transfer => 'warning',
            self::Wallet => 'gray',
        };
    }
}
