<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Users\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['outlet_id', 'name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    /**
     * Operator yang dicatat sebagai pembuka sesi kios swalayan — tak ada kasir
     * di sana, jadi transaksinya dikaitkan ke owner. Dipakai semua action kios
     * (PlayFromWallet, StartKioskOpenPlay, checkout) supaya satu definisi saja.
     */
    public static function kioskOperator(): self
    {
        return self::query()->where('role', UserRole::Owner)->orderBy('id')->firstOrFail();
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function openedSessions(): HasMany
    {
        return $this->hasMany(RentalSession::class, 'opened_by');
    }
}
