<?php

namespace App\Domain\Wallet;

use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\RentalSession;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SATU-SATUNYA tempat saldo boleh berubah.
 *
 * Bukan kerapian, melainkan syarat agar angkanya bisa dipercaya. Saldo hidup
 * di kolom `customers.balance` supaya tiap layar kios tidak perlu menjumlahkan
 * seluruh riwayat, dan buku besarnya di `wallet_transactions`. Keduanya hanya
 * bisa tetap cocok kalau setiap perubahan lewat sini: mengunci baris
 * pelanggannya, mengubah saldo, dan menulis satu baris buku besar — ketiganya
 * di dalam satu transaksi database.
 *
 * Tanpa kunci baris, dua permintaan yang datang bersamaan membaca saldo yang
 * sama lalu menuliskan hasil masing-masing: pelanggan bisa membelanjakan uang
 * yang sama dua kali, dan selisihnya baru ketahuan saat menutup kas.
 */
class Wallet
{
    public function topUp(Customer $customer, int $amount, ?Payment $payment = null, ?User $performedBy = null): WalletTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal isi saldo harus lebih dari nol.');
        }

        return $this->record($customer, WalletTransactionType::TopUp, $amount, [
            'payment_id' => $payment?->id,
            'performed_by' => $performedBy?->id,
            'description' => $payment ? 'Isi saldo lewat '.$payment->method->getLabel() : 'Isi saldo',
        ]);
    }

    public function spend(Customer $customer, int $amount, ?RentalSession $session = null): WalletTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal pemakaian harus lebih dari nol.');
        }

        return $this->record($customer, WalletTransactionType::Spend, -$amount, [
            'rental_session_id' => $session?->id,
            'description' => $session?->unit?->code ? 'Main di '.$session->unit->code : 'Pemakaian saldo',
        ]);
    }

    /**
     * Mengembalikan saldo, mis. saat sesi yang sudah dibayar dibatalkan.
     * Dipisah dari topUp() supaya laporan bisa membedakan uang yang benar-benar
     * masuk dari uang yang cuma dikembalikan.
     */
    public function refund(Customer $customer, int $amount, ?RentalSession $session = null, ?User $performedBy = null): WalletTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal pengembalian harus lebih dari nol.');
        }

        return $this->record($customer, WalletTransactionType::Refund, $amount, [
            'rental_session_id' => $session?->id,
            'performed_by' => $performedBy?->id,
            'description' => 'Pengembalian saldo',
        ]);
    }

    /**
     * Koreksi manual oleh owner. WAJIB beralasan: angka saldo yang berubah
     * tanpa keterangan tidak bisa dipertanggungjawabkan siapa pun, dan justru
     * baris seperti inilah yang pertama dicari saat ada dugaan penyalahgunaan.
     */
    public function adjust(Customer $customer, int $amount, string $reason, User $performedBy): WalletTransaction
    {
        if ($amount === 0) {
            throw new InvalidArgumentException('Koreksi nol tidak mengubah apa pun.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Alasan koreksi wajib diisi.');
        }

        return $this->record($customer, WalletTransactionType::Adjustment, $amount, [
            'performed_by' => $performedBy->id,
            'description' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(Customer $customer, WalletTransactionType $type, int $amount, array $attributes): WalletTransaction
    {
        return DB::transaction(function () use ($customer, $type, $amount, $attributes): WalletTransaction {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $balanceAfter = $locked->balance + $amount;

            // Saldo tidak boleh pernah negatif. Ini penjaga TERAKHIR, bukan
            // satu-satunya: pemanggil tetap harus memeriksa lebih dulu supaya
            // pelanggan mendapat pesan yang masuk akal, bukan exception.
            if ($balanceAfter < 0) {
                throw new InsufficientBalanceException('Saldo tidak cukup.');
            }

            $locked->update(['balance' => $balanceAfter]);

            $transaction = $locked->walletTransactions()->create([
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                ...$attributes,
            ]);

            activity()
                ->performedOn($locked)
                ->withProperties(['type' => $type->value, 'amount' => $amount, 'balance_after' => $balanceAfter])
                ->event('wallet_'.$type->value)
                ->log('Saldo pelanggan berubah');

            // Objek yang dipegang pemanggil ikut disegarkan: membiarkannya
            // memegang saldo lama adalah cara paling mudah membelanjakan uang
            // yang sudah tidak ada.
            $customer->refresh();

            return $transaction;
        });
    }
}
