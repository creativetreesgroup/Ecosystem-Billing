<?php

namespace App\Policies;

use App\Domain\Users\UserRole;
use App\Models\Package;
use App\Models\User;

class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function view(User $user, Package $package): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function update(User $user, Package $package): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function delete(User $user, Package $package): bool
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

    public function restore(User $user, Package $package): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function forceDelete(User $user, Package $package): bool
    {
        return false;
    }
}
