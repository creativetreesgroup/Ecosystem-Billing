<?php

namespace App\Policies;

use App\Models\RentalSession;
use App\Models\User;

class RentalSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:RentalSession');
    }

    public function view(User $user, RentalSession $rentalSession): bool
    {
        return $user->checkPermissionTo('View:RentalSession');
    }

    public function create(User $user): bool
    {
        // Sesi hanya dibuat lewat StartSessionAction dari dashboard kasir,
        // tidak pernah lewat form Filament langsung.
        return false;
    }

    public function update(User $user, RentalSession $rentalSession): bool
    {
        // Mutasi hanya lewat action domain (Extend/Complete/Void), bukan
        // edit field mentah — supaya kalkulasi & activity log tidak terlewati.
        return false;
    }

    public function void(User $user, RentalSession $rentalSession): bool
    {
        return $user->checkPermissionTo('Void:RentalSession');
    }

    public function delete(User $user, RentalSession $rentalSession): bool
    {
        return false;
    }

    public function restore(User $user, RentalSession $rentalSession): bool
    {
        return false;
    }

    public function forceDelete(User $user, RentalSession $rentalSession): bool
    {
        return false;
    }
}
