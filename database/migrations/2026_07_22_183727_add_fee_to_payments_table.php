<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // Biaya admin isi saldo (QRIS/transfer). `amount` = TOTAL yang dibayar
            // pelanggan (kotor, dipakai gateway); saldo yang masuk = amount - fee.
            // Default 0 supaya pembayaran lama & tunai tetap benar tanpa disentuh.
            $table->unsignedInteger('fee')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('fee');
        });
    }
};
