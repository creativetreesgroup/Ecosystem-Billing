<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Melepas kolom peran lama, setelah pemegangnya dipindahkan ke Shield.
 *
 * Kolom ini sudah tidak menentukan apa pun sejak otorisasi pindah ke Shield —
 * tapi selama ia masih ada, ia berbohong: form Pengguna menampilkannya seolah
 * mengubahnya berarti sesuatu. Dua sumber kebenaran untuk "orang ini boleh
 * apa" adalah cara paling pasti keduanya menyimpang.
 *
 * Pemindahannya dijalankan LEBIH DULU dan hanya untuk akun yang belum punya
 * peran Shield sama sekali: instalasi yang sudah menugaskan peran lewat panel
 * tidak boleh ditimpa oleh tebakan dari kolom lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            return;
        }

        $this->migrateExistingUsers();

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }

    /**
     * Turun tidak bisa mengembalikan nilainya — peran sudah pindah rumah.
     * Kolomnya dibuat ulang kosong supaya rollback tetap menghasilkan skema
     * yang sah, bukan supaya datanya kembali.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->nullable()->after('password');
        });
    }

    private function migrateExistingUsers(): void
    {
        $map = [
            'owner' => 'super_admin',
            // Kasir lantai bekerja lintas departemen — itu sebabnya tiga.
            'kasir' => ['staf_operasional', 'staf_maintenance', 'staf_keuangan'],
        ];

        foreach (DB::table('users')->select('id', 'role')->get() as $row) {
            $user = User::find($row->id);

            if (! $user || $user->roles()->exists()) {
                continue;
            }

            $names = (array) ($map[$row->role] ?? []);
            $existing = Role::query()->whereIn('name', $names)->pluck('name')->all();

            if ($existing !== []) {
                $user->syncRoles($existing);
            }
        }
    }
};
