<?php

namespace App\Policies;

use App\Models\MenuItem;
use App\Models\User;
use App\Models\UserRole;

class MenuItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function view(User $user, MenuItem $menuItem): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function update(User $user, MenuItem $menuItem): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function delete(User $user, MenuItem $menuItem): bool
    {
        return $user->role === UserRole::Owner;
    }

    /** Eksplisit: metode policy yang TIDAK ADA dianggap "boleh" oleh Filament. */
    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function restore(User $user, MenuItem $menuItem): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function forceDelete(User $user, MenuItem $menuItem): bool
    {
        return false;
    }
}
