<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesi yang dimulai dari kios milik pelanggan tertentu.
 *
 * customer_name TETAP ada dan tidak digantikan: tamu tanpa akun masih dilayani
 * kasir dengan mengetik namanya, dan riwayat lama tidak boleh kehilangan nama
 * yang sudah tercatat di sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_sessions', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('opened_by')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
