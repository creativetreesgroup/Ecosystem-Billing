<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\OpenPlay;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\SessionTotal;
use App\Domain\Devices\DeviceManager;
use App\Domain\Discounts\DiscountEngine;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Domain\Sessions\Events\SessionEnded;
use App\Domain\Sessions\Exceptions\SessionTooShortException;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Wallet\Events\CustomerWentIntoDebt;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
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
        private readonly DiscountEngine $discounts,
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

        $rawBill = SessionTotal::for($session, $endedAt);

        $wentIntoDebt = false;
        $justStopped = false;

        DB::transaction(function () use ($session, $rawBill, $endedAt, &$wentIntoDebt, &$justStopped): void {
            // Kunci ulang di dalam transaksi: sesi yang sudah selesai (mis. oleh
            // job backstop plafon) tidak boleh ditagih dua kali.
            $locked = RentalSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SessionStatus::Active) {
                return;
            }

            $justStopped = true;

            // Kunci baris pelanggan sebagai baca PERTAMA — lewat kolom
            // customer_id, BUKAN relasi $locked->customer (yang memicu SELECT
            // non-kunci lebih dulu). Dua stop Open Play bersamaan untuk pelanggan
            // yang sama (dua unit) dulu menghitung split saldo/kredit dari saldo
            // basi lalu spend() menembus lantai 0 → InsufficientBalanceException
            // tak tertangkap (500 kios / job backstop batal). Selain itu, pola
            // baca-non-kunci-lalu-FOR-UPDATE pada baris yang sama memicu error
            // MariaDB 1020 "record has changed" saat ada modifikasi bersamaan.
            // Mengunci duluan membuat proses lain MENUNGGU kunci lalu membaca
            // saldo terkini — split dihitung dari saldo yang stabil.
            $customer = $locked->customer_id !== null
                ? Customer::query()->whereKey($locked->customer_id)->lockForUpdate()->firstOrFail()
                : null;

            // Voucher Open Play: potongan dihitung dari TAGIHAN AKHIR di sini
            // (bukan saat mulai — tagihannya belum ada). Gagal ditebus (mis.
            // kuota habis saat main) → tagih penuh + log; JANGAN gagalkan
            // penghentiannya, pelanggan sudah terlanjur main.
            // Voucher (disimpan saat mulai) atau promo otomatis bila tak ada kode.
            $discount = 0;

            try {
                $discount = $this->discounts->apply(
                    $locked->voucher_code,
                    DiscountTarget::OpenPlay,
                    $rawBill,
                    $customer,
                    ['rental_session_id' => $locked->id],
                )?->amount ?? 0;
            } catch (DiscountNotApplicableException $e) {
                Log::warning('Voucher Open Play gagal ditebus saat berhenti; ditagih penuh.', [
                    'session_id' => $locked->id,
                    'reason' => $e->getMessage(),
                ]);
            }

            $bill = max(0, $rawBill - $discount);
            $fromBalance = max(0, min($bill, $customer->balance));
            $credit = $bill - $fromBalance;

            if ($fromBalance > 0) {
                $this->wallet->spend($customer, $fromBalance, $locked);
            }

            if ($credit > 0) {
                // Dijepit ke SISA jarak menuju lantai −plafon, BUKAN ke nilai
                // plafon. Kalau saldo sudah minus sebelum tagihan dihitung
                // (mis. pelanggan memakai dua unit sekaligus, atau owner
                // membebankan denda), min(credit, 50k) bisa menembus lantai —
                // spendOnCredit lalu melempar, sesi jadi tak bisa di-stop dan
                // job backstop ikut crash. headroom = 0 saat sudah di lantai,
                // jadi tidak ada penarikan kredit dan seluruh tagihan tercatat
                // sebagai rugi outlet.
                $headroom = max(0, $customer->balance + OpenPlay::CREDIT_CEILING);
                $onCredit = min($credit, $headroom);

                if ($onCredit > 0) {
                    $this->wallet->spendOnCredit($customer, $onCredit, OpenPlay::CREDIT_CEILING, $locked);
                    $wentIntoDebt = true;
                }

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
                'discount_amount' => $discount,
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

        // Efek samping HANYA bila sesi ini yang benar-benar menghentikannya —
        // bukan saat transaksi no-op karena sesi sudah selesai (balapan stop
        // manual vs job backstop di menit yang sama). Tanpa gerbang ini,
        // SessionEnded di-broadcast dua kali & idle-screen di-cast ulang.
        if ($justStopped) {
            // Sesi berakhir → TV tampilkan QR lagi untuk pelanggan berikutnya
            // (bukan dimatikan: kios swalayan butuh QR terlihat untuk dipindai).
            $this->devices->showIdleScreen($session->unit);
            SessionEnded::dispatch($session->id, $session->unit_id);

            // Kalau tagihan menembus saldo ke kredit, owner diberi tahu supaya
            // utangnya bisa ditagih (bukan sekadar angka merah yang terlewat).
            if ($wentIntoDebt) {
                CustomerWentIntoDebt::dispatch($session->customer_id);
            }
        }

        return $session->fresh();
    }
}
