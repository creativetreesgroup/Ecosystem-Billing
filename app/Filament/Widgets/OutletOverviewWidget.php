<?php

namespace App\Filament\Widgets;

use App\Domain\Billing\Rupiah;
use App\Domain\Billing\SalesSummary;
use App\Domain\Devices\DeviceAlertStatus;
use App\Models\DeviceAlert;
use App\Models\Unit;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * Ringkasan sekilas di atas grid unit.
 *
 * Yang ditampilkan sengaja dibatasi ke hal yang benar-benar dipakai kasir
 * saat berdiri di depan layar: berapa unit terpakai (butuh dilayani), berapa
 * pendapatan HARI INI (untuk cocok dengan laci saat tutup), dan apakah ada
 * alert perangkat yang belum ditangani. Angka lain sudah ada di Laporan.
 */
class OutletOverviewWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    // Sama alasannya dengan UnitGridWidget: ini konten utama dasbor, bukan
    // widget sekunder di halaman padat. Lazy loading hanya menambah round-trip,
    // dan di proyek ini sudah pernah terbukti jadi sumber widget macet kosong.
    protected static bool $isLazy = false;

    // Fallback kalau WebSocket putus; push utamanya lewat Reverb di bawah.
    protected ?string $pollingInterval = '30s';

    #[On('echo-private:panel.units,.session.started')]
    #[On('echo-private:panel.units,.session.ended')]
    #[On('echo-private:panel.units,.device-alert.raised')]
    public function refreshOverview(): void
    {
        //
    }

    protected function getStats(): array
    {
        $active = Unit::query()->whereHas('activeSession')->count();
        $total = Unit::query()->where('is_active', true)->count();
        $free = max(0, $total - $active);

        $tz = SalesSummary::timezone();
        $today = now($tz)->toDateString();
        $todaySummary = new SalesSummary($today, $today);

        $openAlerts = DeviceAlert::query()->where('status', DeviceAlertStatus::Open)->count();

        return [
            Stat::make('Unit terpakai', "{$active} / {$total}")
                ->description($free > 0 ? "{$free} unit siap dipakai" : 'Semua unit terisi')
                ->descriptionIcon(Heroicon::OutlinedTv)
                ->color($free > 0 ? 'success' : 'warning'),

            Stat::make('Pendapatan hari ini', Rupiah::format($todaySummary->totalRevenue()))
                ->description($todaySummary->totalSessions().' sesi selesai hari ini')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success'),

            Stat::make('Alert perangkat', (string) $openAlerts)
                ->description($openAlerts > 0 ? 'Belum ditangani — cek fisik' : 'Tidak ada masalah')
                ->descriptionIcon($openAlerts > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($openAlerts > 0 ? 'danger' : 'gray'),
        ];
    }
}
