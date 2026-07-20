<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;

/**
 * Pelanggan yang punya akun & saldo.
 *
 * Bukan User: kasir dan owner masuk ke panel yang mengatur outlet; pelanggan
 * masuk ke kios yang menghabiskan saldonya sendiri. Menyatukan keduanya dalam
 * satu tabel berarti satu kebocoran otorisasi memberi pelanggan akses ke
 * laporan pendapatan.
 */
#[Fillable(['name', 'phone', 'pin_hash', 'balance', 'is_active', 'last_seen_at'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'pin_hash' => 'hashed',
            'balance' => 'integer',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function rentalSessions(): HasMany
    {
        return $this->hasMany(RentalSession::class);
    }

    /**
     * PIN disimpan di kolom pin_hash, bukan `password`. Laravel harus
     * diberitahu — kalau tidak, seluruh mekanisme otentikasinya diam-diam
     * membandingkan dengan kolom kosong dan MENOLAK semua orang.
     */
    public function getAuthPassword(): string
    {
        return $this->pin_hash;
    }

    public function verifyPin(string $pin): bool
    {
        return Hash::check($pin, $this->pin_hash);
    }

    public function canAfford(int $amount): bool
    {
        return $this->balance >= $amount;
    }

    /**
     * Buku besar HARUS selalu cocok dengan kolom saldo. Dipakai test, dan
     * tersedia untuk pemeriksaan saat ada sengketa — perbedaan sekecil apa pun
     * berarti ada perubahan saldo yang terjadi tanpa dicatat, dan itu tidak
     * boleh pernah lolos diam-diam.
     */
    public function ledgerBalance(): int
    {
        return (int) $this->walletTransactions()->sum('amount');
    }
}
