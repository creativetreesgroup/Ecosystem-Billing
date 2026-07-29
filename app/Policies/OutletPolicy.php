<?php

namespace App\Policies;

use App\Models\Outlet;
use App\Models\User;

class OutletPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:Outlet');
    }

    public function view(User $user, Outlet $outlet): bool
    {
        return $user->checkPermissionTo('View:Outlet');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('Create:Outlet');
    }

    public function update(User $user, Outlet $outlet): bool
    {
        return $user->checkPermissionTo('Update:Outlet');
    }

    public function delete(User $user, Outlet $outlet): bool
    {
        return $user->checkPermissionTo('Delete:Outlet');
    }

    /**
     * Wajib eksplisit: Filament menganggap metode policy yang TIDAK ADA sebagai
     * "boleh", jadi tanpa ini DeleteBulkAction di tabel terbuka untuk siapa pun
     * yang bisa mencapainya, mengabaikan pembatasan delete per-baris.
     */
    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo('DeleteAny:Outlet');
    }

    public function restore(User $user, Outlet $outlet): bool
    {
        return $user->checkPermissionTo('Restore:Outlet');
    }

    public function forceDelete(User $user, Outlet $outlet): bool
    {
        return false;
    }
}
