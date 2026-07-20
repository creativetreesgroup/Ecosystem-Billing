<?php

namespace App\Models;

use App\Domain\Devices\IntegrationKey;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Kredensial integrasi perangkat, disimpan terenkripsi.
 *
 * §14 semula mensyaratkan HA_TOKEN hanya lewat .env. Batasan itu dilonggarkan
 * dengan sengaja dan tercatat di DECISIONS.md, karena bentuk .env memaksa
 * pemilik outlet masuk ke server dan menyunting berkas hanya untuk menempelkan
 * satu token — sesuatu yang pasti dikerjakan dengan cara paling tidak aman
 * (dikirim lewat chat, diketik orang lain) atau tidak dikerjakan sama sekali.
 *
 * Yang TIDAK dilonggarkan:
 * - Token dienkripsi at rest (cast 'encrypted', kuncinya APP_KEY yang tetap
 *   hanya ada di .env). Dump database saja tidak cukup untuk membacanya.
 * - Token tidak pernah dikirim balik ke browser, tidak pernah masuk log,
 *   pesan exception, respons, maupun payload broadcast.
 * - Hanya owner yang bisa membuka layarnya (IntegrationPolicy).
 */
#[Fillable(['key', 'base_url', 'token', 'options', 'is_active', 'verified_at'])]
class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'key' => IntegrationKey::class,
            'token' => 'encrypted',
            'options' => 'array',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public static function for(IntegrationKey $key): ?self
    {
        return static::query()->where('key', $key)->first();
    }

    /**
     * Sudah cukup lengkap untuk dipakai memanggil API.
     */
    public function isUsable(): bool
    {
        return $this->is_active && filled($this->base_url) && filled($this->token);
    }

    /**
     * Nilai tambahan yang BUKAN rahasia — client key & merchant id Midtrans
     * memang dipakai terbuka di sisi pelanggan. Menaruhnya di kolom token yang
     * terenkripsi salah secara makna dan membuatnya tidak bisa ditampilkan
     * kembali ke operator untuk dicocokkan.
     */
    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * Bentuk aman untuk ditampilkan: cukup untuk membedakan "sudah diisi" dari
     * "belum", tanpa pernah membocorkan tokennya. Empat digit terakhir dipilih
     * supaya operator bisa mencocokkannya dengan yang ia salin dari dashboard
     * tanpa harus melihat keseluruhannya.
     */
    public function maskedToken(): ?string
    {
        if (blank($this->token)) {
            return null;
        }

        return str_repeat('•', 12).substr($this->token, -4);
    }
}
