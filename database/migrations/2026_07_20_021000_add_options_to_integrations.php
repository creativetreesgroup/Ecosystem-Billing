<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Midtrans butuh lebih dari sekadar satu token: ada client key dan merchant id
 * yang BUKAN rahasia (client key memang dipakai di sisi pelanggan), serta
 * pilihan sandbox/produksi. Menaruhnya di kolom `token` yang terenkripsi salah
 * secara makna dan membuatnya tidak bisa ditampilkan kembali ke operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->json('options')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
