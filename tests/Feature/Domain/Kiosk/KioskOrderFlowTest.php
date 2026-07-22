<?php

use App\Domain\Menu\MenuOrderStatus;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuOrder;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

/**
 * Memesan jajanan dari layar kios, dari sudut pandang pelanggannya.
 */
beforeEach(function () {
    User::factory()->owner()->create();
    $this->unit = Unit::factory()->create();
    $category = MenuCategory::factory()->create(['name' => 'Snack']);
    $this->indomie = MenuItem::factory()->for($category, 'category')->create(['name' => 'Indomie Goreng', 'price' => 8_000]);
    $this->teh = MenuItem::factory()->for($category, 'category')->create(['name' => 'Es Teh', 'price' => 5_000]);
});

test('a customer builds a cart and orders — balance debited, order queued for staff', function () {
    $customer = Customer::factory()->withBalance(50_000)->create();

    Livewire::actingAs($customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('tab', 'order')
        ->call('addToCart', $this->indomie->id)
        ->call('addToCart', $this->indomie->id)
        ->call('addToCart', $this->teh->id)
        ->assertSet('cart', [$this->indomie->id => 2, $this->teh->id => 1])
        ->call('askOrder')
        ->assertSet('confirm', 'order')
        ->call('placeOrder')
        ->assertHasNoErrors()
        ->assertSet('cart', [])
        ->assertSet('confirm', null);

    $order = MenuOrder::sole();

    expect($order->total_amount)->toBe(21_000)
        ->and($order->status)->toBe(MenuOrderStatus::Placed)
        ->and($order->unit_id)->toBe($this->unit->id)
        ->and($order->customer_id)->toBe($customer->id)
        ->and($customer->fresh()->balance)->toBe(29_000);
});

test('ordering more than the balance is refused with a friendly message, nothing charged', function () {
    $customer = Customer::factory()->withBalance(5_000)->create();

    Livewire::actingAs($customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('tab', 'order')
        ->call('addToCart', $this->indomie->id)
        ->call('askOrder')
        ->call('placeOrder')
        ->assertSet('error', 'Saldo belum cukup untuk pesanan ini. Isi saldo dulu.');

    expect(MenuOrder::count())->toBe(0)
        ->and($customer->fresh()->balance)->toBe(5_000);
});

test('the minus button removes an item and an empty cart cannot be ordered', function () {
    $customer = Customer::factory()->withBalance(50_000)->create();

    Livewire::actingAs($customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('tab', 'order')
        ->call('addToCart', $this->teh->id)
        ->call('removeFromCart', $this->teh->id)
        ->assertSet('cart', [])
        ->call('askOrder')
        ->assertSet('error', 'Keranjang masih kosong.')
        ->assertSet('confirm', null);

    expect(MenuOrder::count())->toBe(0);
});

test('a guest cannot place an order by calling the action directly', function () {
    Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('cart', [$this->teh->id => 1])
        ->call('placeOrder')
        ->assertOk();

    expect(MenuOrder::count())->toBe(0);
});
