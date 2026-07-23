<?php

namespace App\Models;

use App\Domain\Users\PermissionGroup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Peran, dengan dua hal yang tidak dipunyai peran bawaan Spatie: departemen
 * tempat ia bekerja, dan apakah ia memimpin departemen itu.
 *
 * Lihat migrasi add_group_to_roles_table untuk alasan keduanya ada.
 */
#[Fillable(['name', 'guard_name', 'group', 'is_group_admin'])]
class Role extends SpatieRole
{
    protected function casts(): array
    {
        return [
            'group' => PermissionGroup::class,
            'is_group_admin' => 'boolean',
        ];
    }

    /**
     * Izin yang BOLEH dipegang peran ini.
     *
     * Peran bergrup dibatasi ke izin grupnya — pembatasan ini yang membuat
     * "Admin Keuangan" tidak bisa diam-diam diberi akses ke Pengguna hanya
     * karena kotak centangnya kebetulan ada di halaman yang sama. Peran tanpa
     * grup (super admin) tidak dibatasi.
     */
    public function assignablePermissions(): Collection
    {
        return $this->group?->permissionNames()
            ?? Permission::query()->pluck('name');
    }

    /**
     * Admin departemen memegang seluruh izin grupnya — dihitung ulang, bukan
     * disalin sekali saat dibuat.
     *
     * Dipanggil setiap kali peran disimpan supaya resource yang ditambahkan
     * bulan depan langsung ikut terpegang. Peran yang dicentang manual tidak
     * punya sifat ini, dan itulah bedanya memimpin departemen dengan sekadar
     * kebetulan punya banyak centang.
     */
    public function syncGroupAdminPermissions(): void
    {
        if (! $this->is_group_admin || ! $this->group) {
            return;
        }

        $this->syncPermissions($this->group->permissionNames()->all());
    }

    protected static function booted(): void
    {
        static::saved(fn (self $role) => $role->syncGroupAdminPermissions());
    }
}
