<?php

namespace App\Models;

use Database\Factories\SessionExtensionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rental_session_id', 'added_minutes', 'amount', 'user_id'])]
class SessionExtension extends Model
{
    /** @use HasFactory<SessionExtensionFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'added_minutes' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function rentalSession(): BelongsTo
    {
        return $this->belongsTo(RentalSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
