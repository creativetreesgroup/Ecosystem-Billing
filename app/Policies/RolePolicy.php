<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Tanpa berkas ini, siapa pun yang bisa masuk panel bisa memberi dirinya
 * SELURUH izin.
 *
 * Filament menganggap "tidak ada policy" sebagai "boleh" (helpers.php
 * mengembalikan Response::allow() saat policy tak ditemukan), dan Shield hanya
 * mendaftarkan policy untuk Role kalau kelas ini ada. Jadi layar Peran & Izin —
 * satu-satunya layar yang mengatur semua layar lain — berdiri tanpa penjaga:
 * seorang teknisi bisa membuka perannya sendiri, mencentang "pilih semua", dan
 * menyimpannya.
 *
 * Ini persis jebakan yang sudah diperingatkan di OutletPolicy dan UnitPolicy
 * ("metode policy yang TIDAK ADA dianggap boleh"), tapi terlewat justru pada
 * resource yang paling berbahaya.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('ViewAny:Role');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->checkPermissionTo('View:Role');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('Create:Role');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->checkPermissionTo('Update:Role');
    }

    /**
     * Peran super admin TIDAK bisa diubah dari panel, oleh siapa pun.
     *
     * Ia satu-satunya peran yang memegang seluruh izin, termasuk izin untuk
     * mengubah peran. Membiarkannya disunting dari layar berarti satu centang
     * keliru bisa mengunci semua orang keluar dari sistemnya sendiri, tanpa
     * jalan pulih di dalam aplikasi.
     */
    public function delete(User $user, Role $role): bool
    {
        if ($role->name === config('filament-shield.super_admin.name', 'super_admin')) {
            return false;
        }

        return $user->checkPermissionTo('Delete:Role');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Role $role): bool
    {
        return false;
    }

    public function restore(User $user, Role $role): bool
    {
        return false;
    }
}
