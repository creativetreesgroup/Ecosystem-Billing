<?php

namespace App\Models;

use App\Domain\Settings\SettingKey;
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
            'key' => SettingKey::class,
            'value' => 'array',
        ];
    }

    public function label(): string
    {
        return $this->key->getLabel();
    }

    /**
     * Nilai skalar sebuah pengaturan, bukan pembungkus arraynya.
     *
     * Dulu tiap pemanggil menulis sendiri `Setting::get('x')['minutes'] ?? 1`
     * — bentuk penyimpanan DAN nilai cadangannya tersebar di beberapa berkas,
     * sehingga mengubah salah satunya berarti mencari semua salinannya.
     * Cadangannya kini datang dari enum, satu tempat.
     */
    public static function get(SettingKey $key): int|string
    {
        $stored = static::query()->where('key', $key)->value('value');

        return $stored['value'] ?? $key->default();
    }

    public static function put(SettingKey $key, int|string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => ['value' => $value]]);
    }

    /**
     * Apakah rekening tujuan transfer sudah lengkap. Menawarkan pembayaran
     * transfer dengan rekening kosong berarti mengirim pelanggan ke tujuan
     * yang tidak ada.
     */
    public static function transferAccountIsComplete(): bool
    {
        foreach (SettingKey::cases() as $key) {
            if ($key->isRequiredForTransfer() && blank(static::get($key))) {
                return false;
            }
        }

        return true;
    }
}
