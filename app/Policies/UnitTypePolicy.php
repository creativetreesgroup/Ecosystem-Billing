<?php

namespace App\Policies;

use App\Models\UnitType;
use App\Models\User;

class UnitTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:UnitType');
    }

    public function view(User $user, UnitType $unitType): bool
    {
        return $user->checkPermissionTo('View:UnitType');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('Create:UnitType');
    }

    public function update(User $user, UnitType $unitType): bool
    {
        return $user->checkPermissionTo('Update:UnitType');
    }

    public function delete(User $user, UnitType $unitType): bool
    {
        return $user->checkPermissionTo('Delete:UnitType');
    }

    /**
     * Wajib eksplisit: Filament menganggap metode policy yang TIDAK ADA sebagai
     * "boleh", jadi tanpa ini DeleteBulkAction di tabel terbuka untuk siapa pun
     * yang bisa mencapainya, mengabaikan pembatasan delete per-baris.
     */
    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo('DeleteAny:UnitType');
    }

    public function restore(User $user, UnitType $unitType): bool
    {
        return $user->checkPermissionTo('Restore:UnitType');
    }

    public function forceDelete(User $user, UnitType $unitType): bool
    {
        return false;
    }
}
