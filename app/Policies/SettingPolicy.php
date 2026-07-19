<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserRole;

class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function view(User $user, Setting $setting): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function create(User $user): bool
    {
        // Kunci setting sudah ditentukan sistem (lihat seeder) — owner mengubah
        // nilainya, bukan menambah baris baru sembarangan.
        return false;
    }

    public function update(User $user, Setting $setting): bool
    {
        return $user->role === UserRole::Owner;
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
