<?php

use App\Domain\Discounts\DiscountSource;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\DiscountType;
use App\Filament\Resources\Discounts\DiscountResource;
use App\Filament\Resources\Discounts\Pages\CreateDiscount;
use App\Models\Discount;
use App\Models\User;
use App\Policies\DiscountPolicy;
use Livewire\Livewire;

test('an owner creates a voucher and the code is stored uppercased', function () {
    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)->test(CreateDiscount::class)
        ->fillForm([
            'source' => DiscountSource::Voucher->value,
            'code' => 'hemat20',
            'name' => 'Diskon Pembukaan',
            'type' => DiscountType::Percentage->value,
            'value' => 20,
            'targets' => [DiscountTarget::Package->value],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $discount = Discount::sole();
    expect($discount->code)->toBe('HEMAT20')
        ->and($discount->type)->toBe(DiscountType::Percentage)
        ->and($discount->value)->toBe(20)
        ->and($discount->appliesTo(DiscountTarget::Package))->toBeTrue();
});

/**
 * Diskon menyentuh harga — bukan wewenang kasir. Resource-nya owner-only.
 */
test('a cashier cannot manage discounts, an owner can', function () {
    $kasir = User::factory()->create();
    $owner = User::factory()->owner()->create();

    expect(app(DiscountPolicy::class)->viewAny($kasir))->toBeFalse()
        ->and(app(DiscountPolicy::class)->viewAny($owner))->toBeTrue();

    $this->actingAs($kasir);
    expect(DiscountResource::canViewAny())->toBeFalse();

    $this->actingAs($owner);
    expect(DiscountResource::canViewAny())->toBeTrue();
});

/**
 * Diskon tak pernah dihapus (memegang jejak redemption) — dinonaktifkan lewat
 * kolom Aktif.
 */
test('discounts cannot be deleted', function () {
    expect(app(DiscountPolicy::class)->delete(User::factory()->owner()->create(), Discount::factory()->create()))->toBeFalse();
});
