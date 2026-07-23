<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Menu\Actions\PlaceMenuOrderAction;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Domain\Wallet\Wallet;
use App\Domain\Wallet\WalletTransactionType;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\MenuOrder;
use App\Models\Package;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;

/**
 * "Saldo terpotong tapi transaksinya tidak jadi" adalah ketakutan paling nyata
 * pelanggan sistem uang. Jawabannya di sini bukan kompensasi belakangan,
 * melainkan ATOMISITAS: potongan saldo dan barang yang dibelinya menempel di
 * satu transaksi database, jadi mati listrik di detik paling buruk sekalipun
 * membuat KEDUANYA batal — bukan salah satunya.
 *
 * Kematian proses disimulasikan dengan melempar TEPAT setelah baris buku besar
 * ditulis tapi sebelum commit: titik paling berbahaya di seluruh alur.
 */
beforeEach(function () {
    User::factory()->owner()->create();
    $this->customer = Customer::factory()->create(['balance' => 100_000]);
    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
});

/** Wallet yang "mati" persis setelah saldo dipotong, sebelum pemanggilnya selesai. */
function walletThatDiesAfterDebiting(): void
{
    app()->bind(Wallet::class, fn () => new class extends Wallet
    {
        public function spend(Customer $customer, int $amount, ?RentalSession $session = null): WalletTransaction
        {
            parent::spend($customer, $amount, $session);

            throw new RuntimeException('listrik mati tepat setelah saldo dipotong');
        }

        public function spendOnOrder(Customer $customer, int $amount, MenuOrder $order): WalletTransaction
        {
            parent::spendOnOrder($customer, $amount, $order);

            throw new RuntimeException('listrik mati tepat setelah saldo dipotong');
        }
    });
}

test('a crash right after the debit leaves the balance untouched and no session behind', function () {
    $package = Package::factory()->for($this->unit->unitType)->create(['price' => 25_000, 'duration_minutes' => 60]);
    walletThatDiesAfterDebiting();

    expect(fn () => app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $package))
        ->toThrow(RuntimeException::class);

    expect($this->customer->fresh()->balance)->toBe(100_000)
        ->and(WalletTransaction::count())->toBe(0)
        ->and(RentalSession::count())->toBe(0);
});

test('a crash right after the debit leaves no half-paid food order', function () {
    $item = MenuItem::factory()->create(['price' => 15_000, 'is_active' => true]);
    walletThatDiesAfterDebiting();

    expect(fn () => app(PlaceMenuOrderAction::class)->handle($this->customer, $this->unit, [$item->id => 2]))
        ->toThrow(RuntimeException::class);

    expect($this->customer->fresh()->balance)->toBe(100_000)
        ->and(WalletTransaction::count())->toBe(0)
        ->and(MenuOrder::count())->toBe(0);
});

/**
 * Kebalikannya juga harus benar: kalau tidak ada yang gagal, potongan DAN
 * catatannya sama-sama ada. Tanpa ini, "saldo tidak berubah" bisa lolos hanya
 * karena aksinya memang tidak pernah memotong apa pun.
 */
test('without a crash the debit and its ledger row both survive', function () {
    $package = Package::factory()->for($this->unit->unitType)->create(['price' => 25_000, 'duration_minutes' => 60]);

    app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $package);

    expect($this->customer->fresh()->balance)->toBe(75_000)
        ->and(WalletTransaction::query()->sole()->type)->toBe(WalletTransactionType::Spend);
});
