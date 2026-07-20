<?php

namespace App\Domain\Settings;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Daftar pengaturan yang dikenal sistem, beserta sifat tiap nilainya.
 *
 * Sebelum ini label ada di peta statis di dalam model, sementara form dan
 * tabelnya dipaku ke `value.minutes`. Akibatnya pengaturan yang BUKAN menit
 * tidak bisa ditampilkan maupun diedit sama sekali — menambah satu saja berarti
 * menyunting tiga berkas dan berharap tidak ada yang terlewat. Semua sifat
 * sebuah pengaturan kini tinggal di satu tempat: di sini.
 */
enum SettingKey: string implements HasIcon, HasLabel
{
    case BillingIncrementMinutes = 'billing_increment_minutes';
    case WarningBeforeMinutes = 'warning_before_minutes';
    case TransferBankName = 'transfer_bank_name';
    case TransferAccountNumber = 'transfer_account_number';
    case TransferAccountHolder = 'transfer_account_holder';

    public function getLabel(): string
    {
        return match ($this) {
            self::BillingIncrementMinutes => 'Pembulatan billing',
            self::WarningBeforeMinutes => 'Peringatan sebelum sesi habis',
            self::TransferBankName => 'Nama bank',
            self::TransferAccountNumber => 'Nomor rekening',
            self::TransferAccountHolder => 'Atas nama',
        };
    }

    public function type(): SettingType
    {
        return match ($this) {
            self::BillingIncrementMinutes, self::WarningBeforeMinutes => SettingType::Minutes,
            default => SettingType::Text,
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::BillingIncrementMinutes => Heroicon::OutlinedCalculator,
            self::WarningBeforeMinutes => Heroicon::OutlinedBellAlert,
            default => Heroicon::OutlinedBuildingLibrary,
        };
    }

    /**
     * Kalimat konsekuensi, bukan pengulangan label. Yang mengubah pengaturan
     * ini jarang tahu apa akibatnya di tempat lain.
     */
    public function description(): string
    {
        return match ($this) {
            self::BillingIncrementMinutes => 'Lama pakai dibulatkan ke kelipatan ini sebelum ditagih. Angka besar berarti pelanggan membayar lebih dari yang ia pakai.',
            self::WarningBeforeMinutes => 'Sesi paket ditandai akan habis sekian menit sebelum waktunya, supaya kasir sempat menawarkan perpanjangan.',
            self::TransferBankName => 'Ditampilkan ke pelanggan saat memilih pembayaran transfer.',
            self::TransferAccountNumber => 'Nomor yang dituju pelanggan. Salah satu digit berarti uangnya masuk ke rekening orang lain.',
            self::TransferAccountHolder => 'Nama pemilik rekening, supaya pelanggan yakin tidak salah tujuan.',
        };
    }

    public function default(): int|string
    {
        return match ($this) {
            self::BillingIncrementMinutes => 1,
            self::WarningBeforeMinutes => 5,
            default => '',
        };
    }

    /**
     * Pengaturan yang harus sudah terisi sebelum fiturnya boleh dipakai.
     * Rekening kosong berarti pelanggan diberi tujuan transfer yang tidak ada.
     */
    public function isRequiredForTransfer(): bool
    {
        return in_array($this, [self::TransferBankName, self::TransferAccountNumber, self::TransferAccountHolder], true);
    }
}
