<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:Unit');
    }

    public function view(User $user, Unit $unit): bool
    {
        return $user->checkPermissionTo('View:Unit');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('Create:Unit');
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->checkPermissionTo('Update:Unit');
    }

    /**
     * Unit tidak pernah dihapus: rental_sessions menyimpan FK unit_id dengan
     * restrictOnDelete, jadi menghapus unit yang pernah dipakai gagal di DB
     * sekaligus merusak riwayat penagihan. Nonaktifkan lewat `is_active`.
     */
    public function delete(User $user, Unit $unit): bool
    {
        return false;
    }

    /**
     * Filament menanyakan ability `deleteAny` (BUKAN `delete`) untuk bulk
     * action. Tanpa method ini Gate mengembalikan "tidak terdefinisi" dan
     * Filament menganggapnya diizinkan — kasir pun bisa bulk-delete unit.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Unit $unit): bool
    {
        return $user->checkPermissionTo('Restore:Unit');
    }

    public function forceDelete(User $user, Unit $unit): bool
    {
        return false;
    }
}
