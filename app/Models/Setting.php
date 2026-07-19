<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /**
     * Label manusiawi per kunci. Daftar kunci ditentukan sistem (lihat seeder;
     * SettingPolicy::create() selalu false), jadi peta statis ini cukup —
     * owner mengubah nilainya, bukan menambah kunci baru.
     */
    private const LABELS = [
        'billing_increment_minutes' => 'Pembulatan billing',
        'warning_before_minutes' => 'Peringatan sebelum sesi habis',
    ];

    public function label(): string
    {
        return self::LABELS[$this->key] ?? $this->key;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }
}
