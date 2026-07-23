<?php

namespace App\Console\Commands;

use App\Domain\Billing\Rupiah;
use App\Models\Customer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Rekonsiliasi ala bank: saldo yang dipakai layar harus SELALU sama dengan
 * jumlah buku besarnya.
 *
 * `customers.balance` ada supaya kios tidak perlu menjumlahkan seluruh riwayat
 * tiap kali menggambar layar; `wallet_transactions` adalah kebenarannya. Wallet
 * menulis keduanya bersama di bawah satu kunci baris, jadi selisih secara
 * struktur mustahil — dan justru itu alasannya diperiksa. Yang "mustahil" dan
 * tidak pernah diperiksa adalah cara paling umum uang menghilang tanpa ada yang
 * sadar: satu query manual di produksi, satu migrasi yang meleset, satu jalur
 * baru yang lupa lewat Wallet.
 *
 * MELAPOR, bukan membetulkan. Menyamakan angkanya diam-diam menghapus satu-
 * satunya jejak bahwa ada yang salah — dan menebak sisi mana yang benar bisa
 * berarti menghapus uang pelanggan yang sah.
 */
#[Signature('wallet:audit')]
#[Description('Periksa saldo tiap pelanggan terhadap buku besarnya')]
class AuditWalletBalances extends Command
{
    public function handle(): int
    {
        $ledger = '(select coalesce(sum(amount), 0) from wallet_transactions where wallet_transactions.customer_id = customers.id)';

        $mismatched = Customer::query()
            ->selectRaw("customers.*, {$ledger} as ledger_balance")
            ->whereRaw("customers.balance <> {$ledger}")
            ->orderBy('id')
            ->get();

        if ($mismatched->isEmpty()) {
            $this->info('Semua saldo cocok dengan buku besarnya.');

            return self::SUCCESS;
        }

        $this->error($mismatched->count().' saldo TIDAK cocok dengan buku besarnya:');

        $this->table(
            ['ID', 'Pelanggan', 'Saldo tercatat', 'Menurut buku besar', 'Selisih'],
            $mismatched->map(fn (Customer $customer): array => [
                $customer->id,
                $customer->name,
                Rupiah::format($customer->balance),
                Rupiah::format((int) $customer->ledger_balance),
                Rupiah::format($customer->balance - (int) $customer->ledger_balance),
            ])->all(),
        );

        return self::FAILURE;
    }
}
