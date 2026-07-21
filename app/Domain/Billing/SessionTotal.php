<?php

namespace App\Domain\Billing;

use App\Domain\Sessions\SessionType;
use App\Domain\Settings\SettingKey;
use App\Models\RentalSession;
use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * Satu-satunya tempat aturan "berapa total sesi ini" hidup.
 *
 * Sebelumnya rumusnya ada DUA salinan: satu di CompleteSessionAction (yang
 * benar-benar menagih) dan satu di UnitGridWidget::estimateTotal (yang
 * ditampilkan ke kasir sebelum menagih). Dua salinan untuk hal yang sama =
 * angka di layar dan angka yang ditagih bisa berbeda diam-diam begitu salah
 * satunya diubah. OpenPlayBillingCalculator tetap murni (§5.2); kelas ini
 * hanya membaca sesi & setting lalu memanggilnya.
 */
final class SessionTotal
{
    public static function for(RentalSession $session, CarbonInterface $at): int
    {
        if ($session->type === SessionType::Package) {
            // Paket: diskon voucher (bila ada) sudah dihitung saat mulai dan
            // disimpan di discount_amount, jadi total penyelesaian tetap konsisten
            // dengan yang ditagih — tidak menyimpang balik ke harga penuh.
            return max(0, $session->base_amount + $session->extra_amount - $session->discount_amount);
        }

        // Open Play: tagihan mentah per menit. Diskon (persen atas tagihan akhir)
        // diterapkan di StopKioskOpenPlayAction saat berhenti, bukan di sini —
        // supaya perhitungan waktunya tetap murni.
        return OpenPlayBillingCalculator::calculate(
            elapsedSeconds: (int) $session->started_at->diffInSeconds($at),
            hourlyRateRupiah: $session->unit->unitType->hourly_rate,
            incrementMinutes: (int) Setting::get(SettingKey::BillingIncrementMinutes),
        );
    }
}
