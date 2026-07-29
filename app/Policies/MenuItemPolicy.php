<?php

namespace App\Policies;

use App\Models\MenuItem;
use App\Models\User;

class MenuItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:MenuItem');
    }

    public function view(User $user, MenuItem $menuItem): bool
    {
        return $user->checkPermissionTo('View:MenuItem');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('Create:MenuItem');
    }

    public function update(User $user, MenuItem $menuItem): bool
    {
        return $user->checkPermissionTo('Update:MenuItem');
    }

    public function delete(User $user, MenuItem $menuItem): bool
    {
        return $user->checkPermissionTo('Delete:MenuItem');
    }

    /** Eksplisit: metode policy yang TIDAK ADA dianggap "boleh" oleh Filament. */
    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo('DeleteAny:MenuItem');
    }

    public function restore(User $user, MenuItem $menuItem): bool
    {
        return $user->checkPermissionTo('Restore:MenuItem');
    }

    public function forceDelete(User $user, MenuItem $menuItem): bool
    {
        return false;
    }
}
