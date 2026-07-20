<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran kini punya DUA bentuk: membayar sesi, atau mengisi saldo.
 *
 * Keduanya memakai jalur yang sama persis — QRIS lewat Midtrans, transfer
 * dengan bukti yang diperiksa kasir, tunai di kasir — jadi menduplikasi
 * seluruh mesinnya hanya untuk top-up berarti dua tempat yang harus
 * dipelihara, dan dua tempat yang bisa menyimpang dalam menentukan "sudah
 * benar-benar dibayar".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('rental_session_id')->constrained()->nullOnDelete();
        });

        // Isi saldo tidak terikat sesi mana pun.
        DB::statement('ALTER TABLE payments MODIFY COLUMN rental_session_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
