<?php

namespace App\Models;

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\PowerState;
use App\Domain\Sessions\SessionStatus;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'outlet_id', 'unit_type_id', 'code', 'control_driver', 'control_ref',
    'tv_mac', 'capabilities', 'power_state', 'last_seen_at', 'is_active', 'notes',
])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'control_driver' => ControlDriver::class,
            'capabilities' => 'array',
            'power_state' => PowerState::class,
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    public function rentalSessions(): HasMany
    {
        return $this->hasMany(RentalSession::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(RentalSession::class)->where('status', SessionStatus::Active);
    }

    public function deviceAlerts(): HasMany
    {
        return $this->hasMany(DeviceAlert::class);
    }
}
