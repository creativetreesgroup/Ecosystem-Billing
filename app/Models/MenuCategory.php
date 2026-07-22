<?php

namespace App\Models;

use Database\Factories\MenuCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_order', 'is_active'])]
class MenuCategory extends Model
{
    /** @use HasFactory<MenuCategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /**
     * Menu yang boleh ditampilkan di kios: kategori aktif, hanya berisi item
     * aktif, terurut. Kategori yang semua itemnya habis (dinonaktifkan) ikut
     * disaring supaya pelanggan tidak membuka tab kosong.
     *
     * @return Collection<int, MenuCategory>
     */
    public static function activeWithItems(): Collection
    {
        return static::query()
            ->where('is_active', true)
            ->whereHas('items', fn ($query) => $query->where('is_active', true))
            ->with(['items' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
