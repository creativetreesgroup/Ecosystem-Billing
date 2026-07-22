<?php

namespace App\Policies;

use App\Models\MenuCategory;
use App\Models\User;
use App\Models\UserRole;

class MenuCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function view(User $user, MenuCategory $menuCategory): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function update(User $user, MenuCategory $menuCategory): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function delete(User $user, MenuCategory $menuCategory): bool
    {
        return $user->role === UserRole::Owner;
    }

    /** Eksplisit: metode policy yang TIDAK ADA dianggap "boleh" oleh Filament. */
    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function restore(User $user, MenuCategory $menuCategory): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function forceDelete(User $user, MenuCategory $menuCategory): bool
    {
        return false;
    }
}
