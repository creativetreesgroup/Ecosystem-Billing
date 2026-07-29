<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'discount_id', 'customer_id', 'rental_session_id', 'payment_id', 'amount',
])]
class DiscountRedemption extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rentalSession(): BelongsTo
    {
        return $this->belongsTo(RentalSession::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
