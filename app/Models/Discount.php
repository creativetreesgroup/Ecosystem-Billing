<?php

namespace App\Models;

use App\Domain\Discounts\DiscountSource;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\DiscountType;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'code', 'name', 'type', 'source', 'value', 'targets',
    'starts_at', 'ends_at', 'max_uses', 'max_uses_per_customer', 'min_amount', 'is_active',
])]
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'source' => DiscountSource::class,
            'value' => 'integer',
            'targets' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_uses' => 'integer',
            'max_uses_per_customer' => 'integer',
            'min_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Kode selalu disimpan huruf besar tanpa spasi supaya "hemat10", " HEMAT10 ",
     * dan "HEMAT10" menunjuk voucher yang sama — dan pencocokan saat menebus
     * cukup membandingkan bentuk yang sudah diseragamkan.
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : Str::upper(trim($value)),
        );
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    /**
     * @return array<int, DiscountTarget>
     */
    public function targetCases(): array
    {
        return array_map(DiscountTarget::from(...), $this->targets ?? []);
    }

    public function appliesTo(DiscountTarget $target): bool
    {
        return in_array($target->value, $this->targets ?? [], strict: true);
    }
}
