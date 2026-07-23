<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;

class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:Setting');
    }

    public function view(User $user, Setting $setting): bool
    {
        return $user->checkPermissionTo('View:Setting');
    }

    public function create(User $user): bool
    {
        // Kunci setting sudah ditentukan sistem (lihat seeder) — owner mengubah
        // nilainya, bukan menambah baris baru sembarangan.
        return false;
    }

    public function update(User $user, Setting $setting): bool
    {
        return $user->checkPermissionTo('Update:Setting');
    }

    public function delete(User $user, Setting $setting): bool
    {
        return false;
    }

    public function restore(User $user, Setting $setting): bool
    {
        return false;
    }

    public function forceDelete(User $user, Setting $setting): bool
    {
        return false;
    }
}
