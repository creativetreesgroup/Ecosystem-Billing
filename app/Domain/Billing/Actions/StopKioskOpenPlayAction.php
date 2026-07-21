<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\OpenPlay;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\SessionTotal;
use App\Domain\Devices\DeviceManager;
use App\Domain\Sessions\Events\SessionEnded;
use App\Domain\Sessions\Exceptions\SessionTooShortException;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Wallet\Events\CustomerWentIntoDebt;
use App\Domain\Wallet\Wallet;
use App\Models\RentalSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menghentikan & menagih sesi Open Play.
 *
 * Tagihannya dihitung SEKALI dari waktu berhenti, lalu ditarik dari saldo. Yang
 * tertutup saldo dipotong biasa; sisanya (kalau saldo tak cukup) ditarik lewat
 * jalur kredit sampai plafon — di sanalah saldo boleh minus. Sesi tetap "lunas"
 * dari sisi pendapatan (uangnya diakui saat jasa terpakai); saldo minus yang
 * tersisa adalah UTANG pelanggan, diselesaikan terpisah dengan isi saldo.
 */
class StopKioskOpenPlayAction
{
    public function __construct(
        private readonly DeviceManager $devices,
        private readonly Wallet $wallet,
    ) {}

    public function handle(RentalSession $session): RentalSession
    {
        $endedAt = now();
        $elapsed = (int) $session->started_at->diffInSeconds($endedAt);

        // Menit pertama tetap ditagih penuh: tidak boleh nyala lalu berhenti
        // seketika tanpa membayar apa pun.
        if ($elapsed < OpenPlay::MIN_SECONDS) {
            throw new SessionTooShortException(OpenPlay::MIN_SECONDS - $elapsed);
        }

        $bill = SessionTotal::for($session, $endedAt);

        $wentIntoDebt = false;

        DB::transaction(function () use ($session, $bill, $endedAt, &$wentIntoDebt): void {
            // Kunci ulang di dalam transaksi: sesi yang sudah selesai (mis. oleh
            // job backstop plafon) tidak boleh ditagih dua kali.
            $locked = RentalSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SessionStatus::Active) {
                return;
            }

            $customer = $locked->customer;
            $fromBalance = max(0, min($bill, $customer->balance));
            $credit = $bill - $fromBalance;

            if ($fromBalance > 0) {
                $this->wallet->spend($customer, $fromBalance, $locked);
            }

            if ($credit > 0) {
                // Dijepit ke plafon. Kalau auto-stop di plafon gagal dan tagihan
                // sempat lewat, sisanya dicatat sebagai rugi outlet — bukan
                // saldo yang jebol tanpa batas.
                $onCredit = min($credit, OpenPlay::CREDIT_CEILING);
                $this->wallet->spendOnCredit($customer, $onCredit, OpenPlay::CREDIT_CEILING, $locked);
                $wentIntoDebt = true;

                if ($credit > $onCredit) {
                    Log::warning('Open Play melewati plafon kredit; selisih tidak tertagih.', [
                        'session_id' => $locked->id,
                        'uncollected' => $credit - $onCredit,
                    ]);
                }
            }

            $locked->update([
                'ended_at' => $endedAt,
                'status' => SessionStatus::Completed,
                'total_amount' => $bill,
                'paid_at' => $endedAt,
            ]);

            // Baris pembayaran metode Saldo supaya sesi ini masuk pendapatan —
            // uangnya diakui saat jasa terpakai, bukan saat utang dilunasi.
            $locked->payments()->create([
                'method' => PaymentMethod::Wallet,
                'status' => PaymentStatus::Paid,
                'amount' => $bill,
                'verified_at' => $endedAt,
            ]);
        });

        // Sesi berakhir → TV tampilkan QR lagi untuk pelanggan berikutnya (bukan
        // dimatikan: kios swalayan butuh QR terlihat supaya bisa dipindai).
        $this->devices->showIdleScreen($session->unit);
        SessionEnded::dispatch($session->id, $session->unit_id);

        // Di luar transaksi: kalau tagihan menembus saldo ke kredit, owner
        // diberi tahu supaya utangnya bisa ditagih (bukan sekadar angka merah
        // yang mudah terlewat di daftar member).
        if ($wentIntoDebt) {
            CustomerWentIntoDebt::dispatch($session->customer_id);
        }

        return $session->fresh();
    }
}
