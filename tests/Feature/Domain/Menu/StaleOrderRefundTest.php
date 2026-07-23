<?php

use App\Domain\Menu\Actions\PlaceMenuOrderAction;
use App\Domain\Menu\MenuOrderStatus;
use App\Domain\Settings\SettingKey;
use App\Domain\Wallet\WalletTransactionType;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\MenuOrder;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;

/**
 * Uang pesanan jajanan ditarik sejak detik pesanan masuk. Kalau sistem mati,
 * staf tidak pernah melihat antreannya, atau outletnya keburu tutup, pesanan
 * itu mengendap di status "Baru" selamanya — pelanggan sudah membayar makanan
 * yang tidak pernah datang, dan tidak ada apa pun yang mengembalikannya.
 *
 * Sapuan ini yang mengembalikannya, tanpa perlu ada yang ingat.
 */
beforeEach(function () {
    User::factory()->owner()->create();
    $this->customer = Customer::factory()->create(['balance' => 100_000]);
    $this->unit = Unit::factory()->create();
    $this->item = MenuItem::factory()->create(['price' => 15_000, 'is_active' => true]);

    Setting::put(SettingKey::StaleOrderRefundMinutes, 60);
});

function placeOrderAgedMinutes(int $minutes, ?MenuOrderStatus $status = null): MenuOrder
{
    $order = app(PlaceMenuOrderAction::class)->handle(test()->customer, test()->unit, [test()->item->id => 2]);

    $order->forceFill(['created_at' => now()->subMinutes($minutes)]);

    if ($status) {
        $order->status = $status;
    }

    $order->save();

    return $order->fresh();
}

test('an order nobody ever touched is refunded once it goes stale', function () {
    $order = placeOrderAgedMinutes(90);
    expect($this->customer->fresh()->balance)->toBe(70_000);

    $this->artisan('menu:refund-stale-orders')->assertSuccessful();

    expect($order->fresh()->status)->toBe(MenuOrderStatus::Cancelled)
        ->and($this->customer->fresh()->balance)->toBe(100_000)
        ->and($this->customer->walletTransactions()->latest('id')->first()->type)->toBe(WalletTransactionType::Refund);
});

/**
 * "Disiapkan" berarti seorang staf sudah memegang pesanan ini. Membatalkannya
 * otomatis membuat dapur memasak makanan yang uangnya sudah dikembalikan.
 */
test('an order the kitchen already picked up is left alone', function () {
    $order = placeOrderAgedMinutes(90, MenuOrderStatus::Preparing);

    $this->artisan('menu:refund-stale-orders')->assertSuccessful();

    expect($order->fresh()->status)->toBe(MenuOrderStatus::Preparing)
        ->and($this->customer->fresh()->balance)->toBe(70_000);
});

test('a fresh order is left alone', function () {
    $order = placeOrderAgedMinutes(5);

    $this->artisan('menu:refund-stale-orders')->assertSuccessful();

    expect($order->fresh()->status)->toBe(MenuOrderStatus::Placed)
        ->and($this->customer->fresh()->balance)->toBe(70_000);
});

/** Penjadwal memanggilnya berulang kali; pengembalian ganda berarti menciptakan uang. */
test('running the sweep repeatedly refunds the same order only once', function () {
    placeOrderAgedMinutes(90);

    $this->artisan('menu:refund-stale-orders')->assertSuccessful();
    $this->artisan('menu:refund-stale-orders')->assertSuccessful();
    $this->artisan('menu:refund-stale-orders')->assertSuccessful();

    expect($this->customer->fresh()->balance)->toBe(100_000)
        ->and($this->customer->walletTransactions()->where('type', WalletTransactionType::Refund)->count())->toBe(1);
});

/** Batas waktunya bisa disetel: dapur yang lambat tidak boleh dihukum sapuan ini. */
test('the staleness limit is configurable', function () {
    Setting::put(SettingKey::StaleOrderRefundMinutes, 240);
    $order = placeOrderAgedMinutes(90);

    $this->artisan('menu:refund-stale-orders')->assertSuccessful();

    expect($order->fresh()->status)->toBe(MenuOrderStatus::Placed);
});
