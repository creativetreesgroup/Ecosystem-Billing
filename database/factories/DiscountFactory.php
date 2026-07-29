<?php

namespace Database\Factories;

use App\Domain\Discounts\DiscountSource;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\DiscountType;
use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    public function definition(): array
    {
        return [
            'code' => Str::upper(fake()->unique()->bothify('HEMAT##')),
            'name' => 'Diskon '.fake()->word(),
            'type' => DiscountType::Percentage,
            'source' => DiscountSource::Voucher,
            'value' => 10,
            'targets' => [DiscountTarget::Package->value],
            'starts_at' => null,
            'ends_at' => null,
            'max_uses' => null,
            'max_uses_per_customer' => null,
            'min_amount' => 0,
            'is_active' => true,
        ];
    }

    public function fixed(int $rupiah): static
    {
        return $this->state(fn (): array => ['type' => DiscountType::Fixed, 'value' => $rupiah]);
    }

    public function percentage(int $percent): static
    {
        return $this->state(fn (): array => ['type' => DiscountType::Percentage, 'value' => $percent]);
    }

    public function promo(): static
    {
        return $this->state(fn (): array => ['source' => DiscountSource::Promo, 'code' => null]);
    }

    /**
     * @param  array<int, DiscountTarget>  $targets
     */
    public function forTargets(array $targets): static
    {
        return $this->state(fn (): array => ['targets' => array_map(fn (DiscountTarget $t) => $t->value, $targets)]);
    }
}
