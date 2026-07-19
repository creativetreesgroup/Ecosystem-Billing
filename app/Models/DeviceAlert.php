<?php

namespace App\Models;

use App\Domain\Devices\DeviceAlertStatus;
use App\Domain\Devices\DeviceAlertType;
use App\Domain\Devices\Events\DeviceAlertRaised;
use Database\Factories\DeviceAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['unit_id', 'type', 'message', 'status', 'acknowledged_by', 'acknowledged_at'])]
class DeviceAlert extends Model
{
    /** @use HasFactory<DeviceAlertFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => DeviceAlertType::class,
            'status' => DeviceAlertStatus::class,
            'acknowledged_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    protected static function booted(): void
    {
        static::created(function (self $alert): void {
            DeviceAlertRaised::dispatch($alert->id, $alert->unit_id);
        });
    }
}
