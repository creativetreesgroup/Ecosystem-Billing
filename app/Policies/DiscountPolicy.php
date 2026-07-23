<?php

namespace App\Policies;

use App\Domain\Users\UserRole;
use App\Models\Discount;
use App\Models\User;

/**
 * Diskon menyentuh harga — hanya owner yang boleh membuat/mengubahnya. Tidak
 * pernah dihapus: diskon yang sudah terpakai memegang jejak redemption
 * (rekonsiliasi & laporan). Nonaktifkan lewat kolom Aktif sebagai gantinya.
 */
class DiscountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function view(User $user, Discount $discount): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function update(User $user, Discount $discount): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function delete(User $user, Discount $discount): bool
    {
        return false;
    }
}
