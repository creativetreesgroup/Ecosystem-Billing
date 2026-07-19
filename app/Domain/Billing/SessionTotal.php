<?php

namespace App\Domain\Billing;

use App\Domain\Sessions\SessionType;
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
            return $session->base_amount + $session->extra_amount;
        }

        return OpenPlayBillingCalculator::calculate(
            elapsedSeconds: (int) $session->started_at->diffInSeconds($at),
            hourlyRateRupiah: $session->unit->unitType->hourly_rate,
            incrementMinutes: Setting::get('billing_increment_minutes')['minutes'] ?? 1,
        );
    }
}
