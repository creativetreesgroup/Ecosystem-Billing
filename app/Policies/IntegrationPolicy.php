<?php

namespace App\Policies;

use App\Models\Integration;
use App\Models\User;

/**
 * Kredensial integrasi = kendali penuh atas semua TV outlet. Kasir tidak
 * pernah butuh melihatnya, apalagi mengubahnya.
 */
class IntegrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:Integration');
    }

    public function view(User $user, Integration $integration): bool
    {
        return $user->checkPermissionTo('View:Integration');
    }

    /**
     * Daftar integrasi ditentukan sistem (lihat IntegrationKey & seeder) —
     * owner mengisi kredensialnya, bukan menambah baris baru yang tidak akan
     * pernah dibaca driver mana pun.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Integration $integration): bool
    {
        return $user->checkPermissionTo('Update:Integration');
    }

    /**
     * Menghapus barisnya akan membuat sistem diam-diam kembali ke nilai .env
     * yang mungkin sudah kedaluwarsa. Untuk mematikan integrasi, pakai toggle
     * "Aktif" — niatnya terbaca dan bisa dikembalikan.
     */
    public function delete(User $user, Integration $integration): bool
    {
        return false;
    }

    public function restore(User $user, Integration $integration): bool
    {
        return false;
    }

    public function forceDelete(User $user, Integration $integration): bool
    {
        return false;
    }
}
