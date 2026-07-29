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
    case TopUpAdminFee = 'topup_admin_fee';
    case StaleOrderRefundMinutes = 'stale_order_refund_minutes';
    case MenuOrderingEnabled = 'menu_ordering_enabled';
    case MenuOpenTime = 'menu_open_time';
    case MenuCloseTime = 'menu_close_time';
    case MenuBreakStartTime = 'menu_break_start_time';
    case MenuBreakEndTime = 'menu_break_end_time';

    // Identitas usaha. Sebelumnya nama outlet dipaku ke APP_NAME di .env,
    // sehingga menggantinya menuntut pemilik masuk ke server, menyunting
    // berkas, lalu membangun ulang cache — untuk mengubah satu tulisan.
    case BusinessName = 'business_name';
    case BrandLogo = 'brand_logo';
    case BrandFavicon = 'brand_favicon';

    public function getLabel(): string
    {
        return match ($this) {
            self::BillingIncrementMinutes => 'Pembulatan billing',
            self::WarningBeforeMinutes => 'Peringatan sebelum sesi habis',
            self::TransferBankName => 'Nama bank',
            self::TransferAccountNumber => 'Nomor rekening',
            self::TransferAccountHolder => 'Atas nama',
            self::TopUpAdminFee => 'Biaya admin isi saldo',
            self::StaleOrderRefundMinutes => 'Batas pesanan terbengkalai',
            self::MenuOrderingEnabled => 'Pemesanan makanan aktif',
            self::MenuOpenTime => 'Jam mulai',
            self::MenuCloseTime => 'Jam tutup',
            self::MenuBreakStartTime => 'Jam mulai istirahat',
            self::MenuBreakEndTime => 'Jam selesai istirahat',
            self::BusinessName => 'Nama usaha',
            self::BrandLogo => 'Logo',
            self::BrandFavicon => 'Favicon',
        };
    }

    public function type(): SettingType
    {
        return match ($this) {
            self::BillingIncrementMinutes, self::WarningBeforeMinutes, self::StaleOrderRefundMinutes => SettingType::Minutes,
            self::TopUpAdminFee => SettingType::Rupiah,
            self::MenuOrderingEnabled => SettingType::Toggle,
            self::MenuOpenTime, self::MenuCloseTime, self::MenuBreakStartTime, self::MenuBreakEndTime => SettingType::Time,
            self::BrandLogo, self::BrandFavicon => SettingType::Image,
            default => SettingType::Text,
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::BillingIncrementMinutes => Heroicon::OutlinedCalculator,
            self::WarningBeforeMinutes => Heroicon::OutlinedBellAlert,
            self::TopUpAdminFee => Heroicon::OutlinedBanknotes,
            self::StaleOrderRefundMinutes => Heroicon::OutlinedArrowUturnLeft,
            self::MenuOrderingEnabled => Heroicon::OutlinedPower,
            self::MenuOpenTime, self::MenuCloseTime => Heroicon::OutlinedClock,
            self::MenuBreakStartTime, self::MenuBreakEndTime => Heroicon::OutlinedPause,
            self::BusinessName => Heroicon::OutlinedBuildingStorefront,
            self::BrandLogo, self::BrandFavicon => Heroicon::OutlinedPhoto,
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
            self::MenuOrderingEnabled => 'Saklar utama pemesanan makanan & minuman. Dimatikan berarti kios menampilkan pemberitahuan alih-alih menu, berapa pun jamnya — dipakai saat dapur mendadak tidak bisa melayani.',
            self::MenuOpenTime => 'Kios mulai menerima pesanan pada jam ini. Boleh melewati tengah malam (mis. 10:00 tutup 02:00). Samakan dengan jam tutup untuk buka 24 jam.',
            self::MenuCloseTime => 'Kios berhenti menerima pesanan pada jam ini. Samakan dengan jam mulai untuk buka 24 jam.',
            self::MenuBreakStartTime => 'Awal jeda istirahat dapur. Selama jeda, kios memberi tahu pelanggan dan menolak pesanan. Kosongkan bila tidak ada istirahat.',
            self::MenuBreakEndTime => 'Akhir jeda istirahat — jam ini yang ditampilkan ke pelanggan sebagai "buka lagi pukul". Kosongkan bila tidak ada istirahat.',
            self::StaleOrderRefundMinutes => 'Pesanan makanan yang tidak pernah disentuh staf selama sekian menit dibatalkan otomatis dan saldonya dikembalikan ke pelanggan. Isi 0 untuk menonaktifkan — pesanan terbengkalai lalu harus dibatalkan manual.',
            self::TopUpAdminFee => 'Biaya tetap yang DITAMBAHKAN ke isi saldo QRIS & transfer (tunai bebas). Pelanggan membayar nominal + biaya ini; saldo yang masuk tetap sebesar nominal pilihannya. Isi 0 untuk menonaktifkan.',
            self::BusinessName => 'Nama usaha yang tampil di panel, judul tab browser, dan halaman kios pelanggan. Dikosongkan berarti memakai APP_NAME dari berkas konfigurasi server.',
            self::BrandLogo => 'Logo di kepala panel, menggantikan tulisan nama usaha. Dikosongkan berarti namanya yang ditampilkan. PNG atau SVG dengan latar transparan paling rapi.',
            self::BrandFavicon => 'Ikon kecil di tab browser. Dikosongkan berarti memakai ikon bawaan aplikasi. Ukuran persegi, minimal 64x64.',
        };
    }

    public function default(): int|string
    {
        return match ($this) {
            self::BillingIncrementMinutes => 1,
            self::WarningBeforeMinutes => 5,
            self::TopUpAdminFee => 2_500,
            self::StaleOrderRefundMinutes => 60,
            // Bawaan sengaja 24 jam & menyala: outlet yang baru dipasang tidak
            // boleh mendapati menunya mati sendiri sebelum sempat diatur.
            self::MenuOrderingEnabled => 1,
            self::MenuOpenTime, self::MenuCloseTime => '00:00',
            default => '',
        };
    }

    /**
     * Pengaturan yang isinya berupa berkas di storage, bukan teks biasa.
     *
     * Dipakai route penyaji aset sebagai daftar-putih. Tanpa penjaga ini,
     * route yang menerima nama pengaturan bisa dipakai membaca nilai
     * pengaturan APA PUN sebagai berkas — termasuk nomor rekening.
     */
    public function isBrandAsset(): bool
    {
        return in_array($this, [self::BrandLogo, self::BrandFavicon], true);
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
