<?php

namespace App\Domain\Wallet;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Settings\SettingKey;
use App\Models\Setting;

/**
 * Biaya admin isi saldo. DITAMBAHKAN di atas nominal pilihan pelanggan untuk
 * QRIS & transfer (menutup biaya perantara / jadi pendapatan outlet); tunai
 * bebas karena tidak ada perantara. Nilainya diatur owner di Pengaturan
 * (SettingKey::TopUpAdminFee) — 0 berarti dimatikan.
 *
 * Satu tempat saja: dipakai OpenTopUpAction (menetapkan tagihan) dan layar kios
 * (menampilkan rincian) supaya keduanya tak pernah menghitung biaya berbeda.
 */
class TopUpFee
{
    public static function for(PaymentMethod $method): int
    {
        if ($method === PaymentMethod::Cash) {
            return 0;
        }

        return max(0, (int) Setting::get(SettingKey::TopUpAdminFee));
    }
}
