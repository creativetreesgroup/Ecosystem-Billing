<?php

namespace App\Policies;

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
        return $user->checkPermissionTo('ViewAny:Discount');
    }

    public function view(User $user, Discount $discount): bool
    {
        return $user->checkPermissionTo('View:Discount');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('Create:Discount');
    }

    public function update(User $user, Discount $discount): bool
    {
        return $user->checkPermissionTo('Update:Discount');
    }

    public function delete(User $user, Discount $discount): bool
    {
        return false;
    }
}
