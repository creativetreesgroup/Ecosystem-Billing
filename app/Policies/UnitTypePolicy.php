<?php

namespace App\Policies;

use App\Models\UnitType;
use App\Models\User;
use App\Models\UserRole;

class UnitTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function view(User $user, UnitType $unitType): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function update(User $user, UnitType $unitType): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function delete(User $user, UnitType $unitType): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function restore(User $user, UnitType $unitType): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function forceDelete(User $user, UnitType $unitType): bool
    {
        return false;
    }
}
