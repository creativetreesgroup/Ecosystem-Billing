<?php

namespace Database\Seeders;

use App\Domain\Users\PermissionGroup;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * Struktur peran bawaan: satu super admin, lalu dua tingkat di tiap departemen.
 *
 * Ini BUKAN seeder demo — ia harus jalan di instalasi produksi. Tanpanya panel
 * berdiri tanpa satu pun peran, dan orang pertama yang masuk tidak bisa
 * melakukan apa pun sampai ada yang menyusun 199 izin dengan tangan.
 *
 * Aman diulang: peran yang sudah ada diperbarui, bukan diduplikasi, dan izin
 * admin grup dihitung ulang — jadi menjalankannya setelah menambah resource
 * baru adalah cara yang benar untuk membagikan izin barunya.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Izin selalu dibuat ulang dari resource yang BENAR-BENAR ada di panel,
        // bukan dari daftar yang ditulis tangan di sini — daftar seperti itu
        // pasti tertinggal pada resource berikutnya yang ditambahkan.
        Artisan::call('shield:generate', [
            '--all' => true,
            '--option' => 'permissions',
            '--panel' => 'admin',
            '--no-interaction' => true,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Super admin tidak tinggal di departemen mana pun: Shield memberinya
        // akses lewat Gate, bukan lewat daftar izin.
        Role::updateOrCreate(
            ['name' => config('filament-shield.super_admin.name', 'super_admin')],
            ['guard_name' => 'web', 'group' => null, 'is_group_admin' => false],
        );

        foreach (PermissionGroup::cases() as $group) {
            $this->seedGroup($group);
        }
    }

    private function seedGroup(PermissionGroup $group): void
    {
        // Admin: seluruh izin grupnya, disinkronkan sendiri oleh model saat
        // disimpan — termasuk izin yang baru ada nanti.
        Role::updateOrCreate(
            ['name' => 'admin_'.$group->value],
            ['guard_name' => 'web', 'group' => $group, 'is_group_admin' => true],
        );

        $staff = Role::updateOrCreate(
            ['name' => 'staf_'.$group->value],
            ['guard_name' => 'web', 'group' => $group, 'is_group_admin' => false],
        );

        // Dieja di PermissionGroup, bukan diturunkan dari rumus — lihat
        // alasannya di staffPermissionNames().
        $staff->syncPermissions($group->staffPermissionNames());
    }
}
