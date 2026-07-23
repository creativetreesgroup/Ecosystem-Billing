<?php

namespace App\Policies;

use App\Models\Package;
use App\Models\User;

class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:Package');
    }

    public function view(User $user, Package $package): bool
    {
        return $user->checkPermissionTo('View:Package');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('Create:Package');
    }

    public function update(User $user, Package $package): bool
    {
        return $user->checkPermissionTo('Update:Package');
    }

    public function delete(User $user, Package $package): bool
    {
        return $user->checkPermissionTo('Delete:Package');
    }

    /**
     * Wajib eksplisit: Filament menganggap metode policy yang TIDAK ADA sebagai
     * "boleh", jadi tanpa ini DeleteBulkAction di tabel terbuka untuk siapa pun
     * yang bisa mencapainya, mengabaikan pembatasan delete per-baris.
     */
    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo('DeleteAny:Package');
    }

    public function restore(User $user, Package $package): bool
    {
        return $user->checkPermissionTo('Restore:Package');
    }

    public function forceDelete(User $user, Package $package): bool
    {
        return false;
    }
}
