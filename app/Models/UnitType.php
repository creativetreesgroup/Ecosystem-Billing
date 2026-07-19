<?php

namespace App\Models;

use Database\Factories\UnitTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['outlet_id', 'name', 'hourly_rate', 'sort_order'])]
class UnitType extends Model
{
    /** @use HasFactory<UnitTypeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}
