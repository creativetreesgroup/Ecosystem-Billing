<?php

namespace App\Policies;

use App\Models\MenuCategory;
use App\Models\User;

class MenuCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:MenuCategory');
    }

    public function view(User $user, MenuCategory $menuCategory): bool
    {
        return $user->checkPermissionTo('View:MenuCategory');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('Create:MenuCategory');
    }

    public function update(User $user, MenuCategory $menuCategory): bool
    {
        return $user->checkPermissionTo('Update:MenuCategory');
    }

    public function delete(User $user, MenuCategory $menuCategory): bool
    {
        return $user->checkPermissionTo('Delete:MenuCategory');
    }

    /** Eksplisit: metode policy yang TIDAK ADA dianggap "boleh" oleh Filament. */
    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo('DeleteAny:MenuCategory');
    }

    public function restore(User $user, MenuCategory $menuCategory): bool
    {
        return $user->checkPermissionTo('Restore:MenuCategory');
    }

    public function forceDelete(User $user, MenuCategory $menuCategory): bool
    {
        return false;
    }
}
