<?php

namespace App\Policies;

use App\Domain\Users\UserRole;
use App\Models\Outlet;
use App\Models\User;

class OutletPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function view(User $user, Outlet $outlet): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function update(User $user, Outlet $outlet): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function delete(User $user, Outlet $outlet): bool
    {
        return $user->role === UserRole::Owner;
    }

    /**
     * Wajib eksplisit: Filament menganggap metode policy yang TIDAK ADA sebagai
     * "boleh", jadi tanpa ini DeleteBulkAction di tabel terbuka untuk siapa pun
     * yang bisa mencapainya, mengabaikan pembatasan delete per-baris.
     */
    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function restore(User $user, Outlet $outlet): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function forceDelete(User $user, Outlet $outlet): bool
    {
        return false;
    }
}
