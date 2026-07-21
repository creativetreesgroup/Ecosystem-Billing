<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Isi saldo tunai yang diterima kasir.
 *
 * Dua hal terjadi sebagai SATU transaksi: satu baris pembayaran tunai yang
 * sudah "Lunas" (untuk laci kas & laporan), dan penambahan saldo lewat Wallet.
 * Keduanya harus atomik — kalau saldo bertambah tapi pembayarannya gagal
 * tercatat, uang di laci tidak akan pernah cocok dengan laporan.
 */
class SettleCashTopUpAction
{
    public function __construct(private readonly Wallet $wallet) {}

    public function handle(Customer $customer, int $amount, User $cashier): WalletTransaction
    {
        // Tanpa batas atas: isi saldo di panel dilakukan operator tepercaya yang
        // menerima uang tunai sungguhan, jadi nominal besar itu sah. Yang dijaga
        // hanya batas bawah. (Batas atas ada di sisi mandiri kios — di sana
        // pengisinya belum tentu tepercaya, lihat OpenTopUpAction::MAXIMUM.)
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal isi saldo harus lebih dari nol.');
        }

        return DB::transaction(function () use ($customer, $amount, $cashier): WalletTransaction {
            // Pembayaran tunai langsung Lunas: kasir yang menerima uangnya,
            // jadi tidak ada yang perlu diverifikasi belakangan. verified_by
            // mencatat SIAPA yang menerimanya.
            $payment = Payment::create([
                'customer_id' => $customer->id,
                'method' => PaymentMethod::Cash,
                'status' => PaymentStatus::Paid,
                'amount' => $amount,
                'verified_by' => $cashier->id,
                'verified_at' => now(),
            ]);

            return $this->wallet->topUp($customer, $amount, $payment, $cashier);
        });
    }
}
