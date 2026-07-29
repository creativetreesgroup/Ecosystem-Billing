<?php

use App\Domain\Menu\Actions\AdvanceMenuOrderAction;
use App\Domain\Menu\Actions\CancelMenuOrderAction;
use App\Domain\Menu\Actions\PlaceMenuOrderAction;
use App\Domain\Menu\MenuOrderStatus;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Wallet;
use App\Domain\Wallet\WalletTransactionType;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;

/**
 * Pesan jajanan dari kios: uangnya ditarik SAAT memesan, dari saldo, dan tidak
 * boleh berutang. Diuji seketat jalur uang lain karena memang jalur uang.
 */
beforeEach(function () {
    $this->unit = Unit::factory()->create();
    $this->staff = User::factory()->create();
    $category = MenuCategory::factory()->create(['name' => 'Snack']);
    $this->indomie = MenuItem::factory()->for($category, 'category')->create(['name' => 'Indomie Goreng', 'price' => 8_000]);
    $this->teh = MenuItem::factory()->for($category, 'category')->create(['name' => 'Es Teh', 'price' => 5_000]);
});

test('ordering debits the wallet exactly and records the order with snapshots', function () {
    $customer = Customer::factory()->withBalance(50_000)->create();

    $order = app(PlaceMenuOrderAction::class)->handle($customer, $this->unit, [
        $this->indomie->id => 2,
        $this->teh->id => 1,
    ]);

    // 2x8.000 + 1x5.000 = 21.000
    expect($order->total_amount)->toBe(21_000)
        ->and($order->status)->toBe(MenuOrderStatus::Placed)
        ->and($order->unit_id)->toBe($this->unit->id)
        ->and($customer->fresh()->balance)->toBe(29_000)
        ->and($order->items)->toHaveCount(2);

    $line = $order->items->firstWhere('name', 'Indomie Goreng');
    expect($line->unit_price)->toBe(8_000)
        ->and($line->quantity)->toBe(2)
        ->and($line->line_total)->toBe(16_000);

    // Buku besar tertaut ke pesanannya, tepat satu baris, bertanda negatif.
    $ledger = WalletTransaction::where('menu_order_id', $order->id)->sole();
    expect($ledger->type)->toBe(WalletTransactionType::Spend)
        ->and($ledger->amount)->toBe(-21_000)
        ->and($ledger->balance_after)->toBe(29_000);
});

test('an order beyond the balance is refused and nothing moves — snacks never go on credit', function () {
    $customer = Customer::factory()->withBalance(10_000)->create();

    expect(fn () => app(PlaceMenuOrderAction::class)->handle($customer, $this->unit, [$this->indomie->id => 2]))
        ->toThrow(InsufficientBalanceException::class);

    expect($customer->fresh()->balance)->toBe(10_000)
        ->and(MenuOrder::count())->toBe(0)
        ->and(WalletTransaction::count())->toBe(0);
});

/**
 * Plafon kredit adalah pengecualian milik Open Play saja. Pelanggan yang sudah
 * pernah top-up (jadi berhak kredit di Open Play) TETAP tidak boleh jajan minus.
 */
test('even a credit-eligible customer cannot order snacks into a negative balance', function () {
    $customer = Customer::factory()->withBalance(0)->create();
    app(Wallet::class)->topUp($customer, 5_000);
    app(Wallet::class)->spend($customer->fresh(), 5_000); // saldo 0, tapi pernah top-up

    expect(fn () => app(PlaceMenuOrderAction::class)->handle($customer->fresh(), $this->unit, [$this->teh->id => 1]))
        ->toThrow(InsufficientBalanceException::class);

    expect($customer->fresh()->balance)->toBe(0)
        ->and(MenuOrder::count())->toBe(0);
});

test('an item deactivated before confirming is refused, not silently skipped', function () {
    $customer = Customer::factory()->withBalance(50_000)->create();
    $this->teh->update(['is_active' => false]);

    expect(fn () => app(PlaceMenuOrderAction::class)->handle($customer, $this->unit, [
        $this->indomie->id => 1,
        $this->teh->id => 1,
    ]))->toThrow(InvalidArgumentException::class);

    expect($customer->fresh()->balance)->toBe(50_000)
        ->and(MenuOrder::count())->toBe(0);
});

test('the price comes from the database, never from the caller', function () {
    $customer = Customer::factory()->withBalance(50_000)->create();

    // Pemanggil hanya boleh mengirim JUMLAH; harga tak bisa dititipkan.
    $order = app(PlaceMenuOrderAction::class)->handle($customer, $this->unit, [$this->indomie->id => 1]);

    expect($order->total_amount)->toBe(8_000)
        ->and($customer->fresh()->balance)->toBe(42_000);
});

test('an empty cart and an absurd quantity are both refused', function () {
    $customer = Customer::factory()->withBalance(1_000_000)->create();

    expect(fn () => app(PlaceMenuOrderAction::class)->handle($customer, $this->unit, []))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(PlaceMenuOrderAction::class)->handle($customer, $this->unit, [$this->teh->id => 100]))
        ->toThrow(InvalidArgumentException::class);

    expect($customer->fresh()->balance)->toBe(1_000_000);
});

test('the item snapshot survives the menu item being deleted', function () {
    $customer = Customer::factory()->withBalance(50_000)->create();
    $order = app(PlaceMenuOrderAction::class)->handle($customer, $this->unit, [$this->indomie->id => 1]);

    $this->indomie->delete();

    $line = $order->fresh()->items->sole();
    expect($line->name)->toBe('Indomie Goreng')
        ->and($line->unit_price)->toBe(8_000)
        ->and($line->menu_item_id)->toBeNull(); // FK dilepas, riwayat tetap utuh
});

test('staff advances the order Baru -> Disiapkan -> Diantar, and no further', function () {
    $customer = Customer::factory()->withBalance(50_000)->create();
    $order = app(PlaceMenuOrderAction::class)->handle($customer, $this->unit, [$this->teh->id => 1]);

    $order = app(AdvanceMenuOrderAction::class)->handle($order, $this->staff);
    expect($order->status)->toBe(MenuOrderStatus::Preparing)
        ->and($order->handled_by)->toBe($this->staff->id);

    $order = app(AdvanceMenuOrderAction::class)->handle($order, $this->staff);
    expect($order->status)->toBe(MenuOrderStatus::Delivered);

    expect(fn () => app(AdvanceMenuOrderAction::class)->handle($order, $this->staff))
        ->toThrow(InvalidArgumentException::class);
});

test('cancelling before delivery refunds the money exactly once', function () {
    // Saldo dibangun lewat Wallet (bukan factory) supaya buku besarnya otoritatif
    // dan konsistensi saldo-vs-ledger benar-benar teruji di sini.
    $customer = Customer::factory()->withBalance(0)->create();
    app(Wallet::class)->topUp($customer, 50_000);
    $order = app(PlaceMenuOrderAction::class)->handle($customer->fresh(), $this->unit, [$this->indomie->id => 2]);
    expect($customer->fresh()->balance)->toBe(34_000);

    $order = app(CancelMenuOrderAction::class)->handle($order, $this->staff);

    expect($order->status)->toBe(MenuOrderStatus::Cancelled)
        ->and($customer->fresh()->balance)->toBe(50_000)
        ->and($customer->fresh()->ledgerBalance())->toBe(50_000);

    // Membatalkan lagi tidak boleh mengembalikan uang kedua kalinya.
    expect(fn () => app(CancelMenuOrderAction::class)->handle($order, $this->staff))
        ->toThrow(InvalidArgumentException::class);

    expect($customer->fresh()->balance)->toBe(50_000)
        ->and(WalletTransaction::where('menu_order_id', $order->id)->where('type', WalletTransactionType::Refund)->count())->toBe(1);
});

test('a delivered order can no longer be cancelled or refunded', function () {
    $customer = Customer::factory()->withBalance(50_000)->create();
    $order = app(PlaceMenuOrderAction::class)->handle($customer, $this->unit, [$this->teh->id => 1]);

    $order = app(AdvanceMenuOrderAction::class)->handle($order, $this->staff);
    $order = app(AdvanceMenuOrderAction::class)->handle($order, $this->staff); // Diantar

    expect(fn () => app(CancelMenuOrderAction::class)->handle($order, $this->staff))
        ->toThrow(InvalidArgumentException::class);

    expect($customer->fresh()->balance)->toBe(45_000); // tetap terpotong
});

test('the kiosk menu shows only active categories that still have active items', function () {
    $kosong = MenuCategory::factory()->create(['name' => 'Kategori Kosong']);
    MenuItem::factory()->for($kosong, 'category')->create(['is_active' => false]);
    MenuCategory::factory()->create(['name' => 'Nonaktif', 'is_active' => false]);

    $names = MenuCategory::activeWithItems()->pluck('name');

    expect($names)->toContain('Snack')
        ->and($names)->not->toContain('Kategori Kosong')
        ->and($names)->not->toContain('Nonaktif');
});
