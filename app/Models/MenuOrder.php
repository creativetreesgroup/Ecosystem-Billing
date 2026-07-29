<?php

namespace App\Models;

use App\Domain\Menu\MenuOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['customer_id', 'unit_id', 'status', 'total_amount', 'handled_by'])]
class MenuOrder extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MenuOrderStatus::class,
            'total_amount' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuOrderItem::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** Ringkasan untuk antrean staf: "Indomie x2, Es teh x1". */
    public function summary(): string
    {
        return $this->items->map(fn (MenuOrderItem $item): string => "{$item->name} x{$item->quantity}")->implode(', ');
    }
}
