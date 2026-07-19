<?php

namespace App\Policies;

use App\Models\Package;
use App\Models\User;
use App\Models\UserRole;

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

    public function restore(User $user, Package $package): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function forceDelete(User $user, Package $package): bool
    {
        return false;
    }
}
