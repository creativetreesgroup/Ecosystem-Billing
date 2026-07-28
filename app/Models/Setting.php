<?php

namespace App\Models;

use App\Domain\Settings\SettingKey;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Invalidasi cache yang AGRESIF & TEPAT: tiap perubahan setting membuang
     * cache barisnya seketika — baik lewat put() maupun form Filament (keduanya
     * menyimpan model), jadi nilai basi tidak pernah bertahan lebih dari satu
     * penyimpanan, tanpa harus menunggu TTL.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $setting) => Cache::forget(self::cacheKey($setting->key)));
        static::deleted(fn (self $setting) => Cache::forget(self::cacheKey($setting->key)));
    }

    private static function cacheKey(SettingKey $key): string
    {
        return 'setting:'.$key->value;
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
        // Dibaca di banyak jalur panas (mis. menit peringatan tiap mulai sesi);
        // disimpan di cache selamanya dan dibuang tepat saat berubah (lihat
        // booted()) — jadi bukan satu query DB per pemanggilan.
        return Cache::rememberForever(self::cacheKey($key), function () use ($key): int|string {
            $stored = static::query()->where('key', $key)->value('value');

            return $stored['value'] ?? $key->default();
        });
    }

    /**
     * Nama usaha yang dipakai panel, judul tab, dan halaman kios.
     *
     * Jatuh ke APP_NAME bila belum diisi, jadi instalasi baru tetap punya nama
     * yang masuk akal alih-alih kepala panel yang kosong.
     */
    public static function brandName(): string
    {
        $name = trim((string) static::get(SettingKey::BusinessName));

        return $name !== '' ? $name : (string) config('app.name');
    }

    /**
     * URL aset merek, atau null bila belum diunggah.
     *
     * Berkasnya TIDAK bisa disajikan langsung oleh nginx: container web
     * me-mount public/ dari host secara read-only, sedangkan unggahan tersimpan
     * di volume milik container app. Karena itu aset dilewatkan route Laravel,
     * pola yang sama dengan gambar kios di routes/web.php.
     *
     * Sidik jari isi berkas ditempelkan sebagai query supaya browser mengambil
     * ulang saat logo diganti — tanpa itu pemilik outlet mengganti logonya dan
     * tidak melihat perubahan apa pun sampai cache-nya kedaluwarsa sendiri.
     */
    public static function brandAssetUrl(SettingKey $key): ?string
    {
        if (! $key->isBrandAsset()) {
            return null;
        }

        $path = trim((string) static::get($key));

        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return route('brand.asset', [
            'key' => $key->value,
            'v' => substr(md5($path.Storage::disk('local')->lastModified($path)), 0, 8),
        ]);
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
