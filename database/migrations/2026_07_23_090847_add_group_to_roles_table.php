<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peran tidak berdiri sendiri: ia bekerja DI DALAM sebuah departemen, dan
 * menempati salah satu dari dua tingkat di sana.
 *
 * `group` menentukan modul mana yang boleh ia sentuh sama sekali. Tanpanya,
 * daftar 199 izin harus dibaca utuh setiap kali peran dibuat — dan yang terjadi
 * di lapangan adalah semuanya dicentang, karena itu jauh lebih cepat daripada
 * memilah.
 *
 * `is_group_admin` menandai pemimpin departemen: ia memegang SELURUH izin
 * grupnya, termasuk izin yang baru ada belakangan. Itu bedanya dengan
 * mencentang manual — peran yang dicentang manual diam-diam tertinggal setiap
 * kali ada resource baru ditambahkan.
 *
 * Keduanya kosong untuk super admin: ia memang tidak tinggal di departemen
 * mana pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('group')->nullable()->after('name')->index();
            $table->boolean('is_group_admin')->default(false)->after('group');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['group', 'is_group_admin']);
        });
    }
};
