<?php

namespace App\Domain\Billing;

use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Satu-satunya sumber angka rekap penjualan.
 *
 * Halaman Laporan, widget statistik, dan widget grafik semuanya bertanya ke
 * sini. Kalau tidak, rumus & batas tanggalnya akan disalin tiga kali dan
 * ketiganya bisa menyimpang diam-diam — persis masalah yang sudah pernah
 * terjadi antara estimasi di dashboard dan tagihan sungguhan (lihat
 * SessionTotal).
 *
 * Tanggal yang masuk selalu JAM DINDING OUTLET; kolom di DB selalu UTC.
 * Konversinya dikurung di satu tempat: range().
 */
final class SalesSummary
{
    private ?Collection $sessions = null;

    public function __construct(
        private readonly ?string $startDate = null,
        private readonly ?string $endDate = null,
    ) {}

    public static function timezone(): string
    {
        return config('app.display_timezone', config('app.timezone'));
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface} batas UTC
     */
    public function range(): array
    {
        $tz = self::timezone();

        $start = Carbon::parse($this->startDate ?? now($tz)->startOfMonth(), $tz)->startOfDay();
        $end = Carbon::parse($this->endDate ?? now($tz), $tz)->endOfDay();

        if ($end->lt($start)) {
            $end = $start->copy()->endOfDay();
        }

        return [$start->utc(), $end->utc()];
    }

    /**
     * @return Collection<int, RentalSession>
     */
    public function sessions(): Collection
    {
        if ($this->sessions) {
            return $this->sessions;
        }

        [$start, $end] = $this->range();

        return $this->sessions = RentalSession::query()
            ->with('unit.unitType')
            ->where('status', SessionStatus::Completed)
            // Selesai TIDAK berarti sudah dibayar. Sejak pembayaran mandiri
            // ada (QRIS lewat gateway, transfer dengan bukti), sebuah sesi
            // bisa berakhir sementara uangnya belum terbukti masuk — QRIS
            // dibatalkan di tengah, atau bukti transfer masih menunggu
            // diperiksa kasir. Menghitungnya sebagai pendapatan membuat
            // laporan berbohong tepat pada angka yang dipakai menutup kas.
            //
            // Sesi yang ditutup kasir tetap ikut: kasir menerima uangnya
            // langsung, jadi pembayarannya dicatat Lunas sejak detik itu.
            ->whereHas('payments', fn (Builder $query) => $query->where('status', PaymentStatus::Paid))
            ->whereBetween('ended_at', [$start, $end])
            ->get();
    }

    public function totalSessions(): int
    {
        return $this->sessions()->count();
    }

    public function totalRevenue(): int
    {
        return (int) $this->sessions()->sum('total_amount');
    }

    public function averageRevenue(): int
    {
        $count = $this->totalSessions();

        return $count === 0 ? 0 : intdiv($this->totalRevenue(), $count);
    }

    public function busiestHour(): ?string
    {
        $byHour = $this->sessions()->groupBy(
            fn (RentalSession $session) => $session->started_at->setTimezone(self::timezone())->format('H')
        );

        if ($byHour->isEmpty()) {
            return null;
        }

        $peak = $byHour->sortByDesc(fn (Collection $group) => $group->count())->keys()->first();

        return "{$peak}:00 – ".str_pad((string) ((int) $peak + 1), 2, '0', STR_PAD_LEFT).':00';
    }

    /**
     * @return array<string, string>
     */
    public function revenueByPaymentMethod(): array
    {
        return $this->summarize(fn (RentalSession $session) => $session->payment_method?->getLabel() ?? 'Tidak diketahui');
    }

    /**
     * @return array<string, string>
     */
    public function revenueByUnitType(): array
    {
        return $this->summarize(fn (RentalSession $session) => $session->unit->unitType->name);
    }

    /**
     * Pendapatan per hari, TERMASUK hari tanpa transaksi — grafik garis yang
     * melompati hari kosong membuat tren terlihat lebih ramai dari kenyataan.
     *
     * @return array{labels: array<int, string>, revenue: array<int, int>, sessions: array<int, int>}
     */
    public function dailySeries(): array
    {
        $tz = self::timezone();
        [$start, $end] = $this->range();

        $byDay = $this->sessions()->groupBy(
            fn (RentalSession $session) => $session->ended_at->setTimezone($tz)->toDateString()
        );

        $labels = [];
        $revenue = [];
        $sessions = [];

        $cursor = $start->copy()->setTimezone($tz)->startOfDay();
        $last = $end->copy()->setTimezone($tz)->startOfDay();

        // Dibatasi 366 titik: rentang yang sangat lebar tidak boleh membuat
        // halaman menggambar ribuan titik dan terasa menggantung.
        for ($i = 0; $cursor->lte($last) && $i < 366; $i++) {
            $key = $cursor->toDateString();
            $group = $byDay->get($key);

            $labels[] = $cursor->format('d M');
            $revenue[] = $group ? (int) $group->sum('total_amount') : 0;
            $sessions[] = $group ? $group->count() : 0;

            $cursor->addDay();
        }

        return ['labels' => $labels, 'revenue' => $revenue, 'sessions' => $sessions];
    }

    /**
     * Rincian per hari yang cukup untuk MENUTUP KAS: kasir/owner perlu tahu
     * berapa uang TUNAI yang mestinya ada di laci hari itu, terpisah dari QRIS
     * dan transfer yang masuk ke rekening. Total saja tidak cukup untuk itu.
     *
     * @return array<int, array{date: string, sessions: int, revenue: int, average: int, share: float, cash: int, qris: int, transfer: int}>
     */
    public function dailyBreakdown(): array
    {
        $tz = self::timezone();
        $total = $this->totalRevenue();

        return $this->sessions()
            ->groupBy(fn (RentalSession $session) => $session->ended_at->setTimezone($tz)->toDateString())
            ->map(function (Collection $group, string $date) use ($total): array {
                $revenue = (int) $group->sum('total_amount');

                $perMethod = collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $method) => [
                    $method->value => (int) $group
                        ->where('payment_method', $method)
                        ->sum('total_amount'),
                ])->all();

                return [
                    'date' => $date,
                    'sessions' => $group->count(),
                    'revenue' => $revenue,
                    'average' => $group->count() === 0 ? 0 : intdiv($revenue, $group->count()),
                    'share' => $total === 0 ? 0.0 : round($revenue / $total * 100, 1),
                    ...$perMethod,
                ];
            })
            ->sortKeysDesc()
            ->values()
            ->all();
    }

    /**
     * Komposisi pendapatan harian per metode bayar, untuk grafik batang
     * bertumpuk. Satu deret per metode, panjangnya sama dengan labels.
     *
     * @return array{labels: array<int, string>, series: array<int, array{name: string, data: array<int, int>}>}
     */
    public function dailyPaymentSeries(): array
    {
        $tz = self::timezone();
        $byDay = $this->sessions()->groupBy(
            fn (RentalSession $session) => $session->ended_at->setTimezone($tz)->toDateString()
        );

        [$start, $end] = $this->range();
        $cursor = $start->copy()->setTimezone($tz)->startOfDay();
        $last = $end->copy()->setTimezone($tz)->startOfDay();

        $labels = [];
        $days = [];

        for ($i = 0; $cursor->lte($last) && $i < 366; $i++) {
            $labels[] = $cursor->format('d M');
            $days[] = $byDay->get($cursor->toDateString());
            $cursor->addDay();
        }

        $series = collect(PaymentMethod::cases())
            ->map(fn (PaymentMethod $method) => [
                'name' => $method->getLabel(),
                'data' => array_map(
                    fn (?Collection $group) => $group
                        ? (int) $group->where('payment_method', $method)->sum('total_amount')
                        : 0,
                    $days,
                ),
            ])
            ->all();

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * @return array<string, string>
     */
    private function summarize(callable $groupBy): array
    {
        $rows = $this->sessions()
            ->groupBy($groupBy)
            ->mapWithKeys(fn (Collection $group, string $label) => [
                "{$label} ({$group->count()} sesi)" => Rupiah::format((int) $group->sum('total_amount')),
            ])
            ->all();

        if ($rows === []) {
            return $rows;
        }

        // Baris total ditambahkan di sini, bukan dihitung ulang di UI, supaya
        // penjumlahannya dijamin cocok dengan baris-baris di atasnya.
        $rows["TOTAL ({$this->totalSessions()} sesi)"] = Rupiah::format($this->totalRevenue());

        return $rows;
    }
}
