<?php

namespace App\Domain\Sessions\Actions;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\SessionTotal;
use App\Domain\Devices\DeviceManager;
use App\Domain\Sessions\Events\SessionEnded;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\RentalSession;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CompleteSessionAction
{
    public function __construct(private readonly DeviceManager $devices) {}

    /**
     * $expectedExpiryToken diisi oleh pemanggil OTOMATIS (job expiry & sweep),
     * dan dibiarkan null oleh penutupan manual kasir. Lihat komentar fencing
     * di dalam transaksi.
     */
    public function handle(RentalSession $session, ?PaymentMethod $paymentMethod = null, ?string $expectedExpiryToken = null): RentalSession
    {
        if ($session->type === SessionType::Open && ! $session->payment_method && ! $paymentMethod) {
            throw new InvalidArgumentException('Metode pembayaran wajib dipilih untuk sesi open play.');
        }

        $justCompleted = false;

        $completed = DB::transaction(function () use ($session, $paymentMethod, $expectedExpiryToken, &$justCompleted) {
            $locked = RentalSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SessionStatus::Active) {
                // Idempotent: job expiry dan sweep bisa balapan menyelesaikan sesi
                // yang sama — panggilan kedua tidak boleh mengubah apa pun, termasuk
                // tidak mengirim ulang perintah TV off atau broadcast SessionEnded.
                return $locked;
            }

            // Fencing token WAJIB dicek ULANG di dalam lock, bukan cuma di
            // pemanggil. Kalau hanya dicek di luar, urutan ini merugikan
            // pelanggan secara nyata: sweep membaca daftar sesi kedaluwarsa →
            // kasir menerima uang perpanjangan (ExtendSessionAction memutar
            // expiry_token & memajukan ends_at, commit) → sweep baru sampai ke
            // sesi itu, melihat status masih Active, lalu MENUTUPNYA. Pelanggan
            // sudah membayar perpanjangan tapi waktunya langsung hangus.
            if ($expectedExpiryToken !== null && $locked->expiry_token !== $expectedExpiryToken) {
                return $locked;
            }

            $before = ['status' => $locked->status->value, 'total_amount' => $locked->total_amount];

            $endedAt = now();

            $totalAmount = SessionTotal::for($locked, $endedAt);

            $locked->update([
                'ended_at' => $endedAt,
                'status' => SessionStatus::Completed,
                'total_amount' => $totalAmount,
                'payment_method' => $locked->payment_method ?? $paymentMethod,
                'paid_at' => $locked->paid_at ?? $endedAt,
            ]);

            activity()
                ->performedOn($locked)
                ->withProperties(['before' => $before, 'after' => ['status' => $locked->status->value, 'total_amount' => $locked->total_amount]])
                ->event('completed')
                ->log('Sesi selesai');

            $this->devices->powerOff($locked->unit);

            $justCompleted = true;

            return $locked->fresh();
        });

        if ($justCompleted) {
            SessionEnded::dispatch($completed->id, $completed->unit_id);
        }

        return $completed;
    }
}
