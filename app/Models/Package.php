<?php

namespace App\Models;

use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

#[Fillable(['unit_type_id', 'name', 'duration_minutes', 'price', 'is_active'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Paket aktif sebuah tipe unit — dibaca tiap polling layar kios (per 10
     * detik, per HP yang terbuka). Disimpan di cache dan dibuang tepat saat
     * paket berubah, jadi bukan satu query DB tiap ketukan poll.
     *
     * Yang DICACHE adalah atribut mentah (array scalar), bukan objek model.
     * `config('cache.serializable_classes') === false` (default keamanan Laravel:
     * cegah gadget-chain saat APP_KEY bocor) membuat store redis meng-unserialize
     * dengan `allowed_classes: false` — objek apa pun terbaca sebagai
     * __PHP_Incomplete_Class dan meledak di return type. Array mentah aman;
     * hydrate() menyusun ulang Collection<Package> yang identik dengan query asli.
     *
     * @return Collection<int, Package>
     */
    public static function activeForUnitType(int $unitTypeId): Collection
    {
        $rows = Cache::rememberForever(self::cacheKey($unitTypeId), fn (): array => static::query()
            ->where('unit_type_id', $unitTypeId)
            ->where('is_active', true)
            ->orderBy('duration_minutes')
            ->get()
            ->map->getAttributes()
            ->all());

        return static::hydrate($rows);
    }

    /**
     * Invalidasi tepat: tiap perubahan paket membuang cache tipe unitnya —
     * termasuk tipe LAMA bila paket dipindah tipe (kalau tidak, daftar tipe lama
     * ikut memuat paket yang sudah pindah).
     */
    protected static function booted(): void
    {
        static::saved(function (self $package): void {
            Cache::forget(self::cacheKey($package->unit_type_id));

            if ($package->wasChanged('unit_type_id')) {
                Cache::forget(self::cacheKey((int) $package->getOriginal('unit_type_id')));
            }
        });

        static::deleted(fn (self $package) => Cache::forget(self::cacheKey($package->unit_type_id)));
    }

    private static function cacheKey(int $unitTypeId): string
    {
        return 'packages:active:unit_type:'.$unitTypeId;
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    public function rentalSessions(): HasMany
    {
        return $this->hasMany(RentalSession::class);
    }
}
