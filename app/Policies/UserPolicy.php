<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserRole;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function view(User $user, User $target): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function update(User $user, User $target): bool
    {
        return $user->role === UserRole::Owner;
    }

    /**
     * Akun tidak pernah dihapus — sesi & extension menyimpan FK ke user
     * (opened_by / voided_by / user_id) dengan restrictOnDelete, jadi
     * menghapus akun kasir yang pernah membuka sesi akan gagal di level DB
     * dan merusak jejak audit. Nonaktifkan lewat `is_active` sebagai
     * gantinya — canAccessPanel() langsung menolak login-nya.
     */
    public function delete(User $user, User $target): bool
    {
        return false;
    }

    public function restore(User $user, User $target): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $target): bool
    {
        return false;
    }
}
