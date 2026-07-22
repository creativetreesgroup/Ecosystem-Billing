<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pesanan jajanan dari kios. Dibayar SAAT DIPESAN dari saldo (floor 0 — jajan
 * tidak boleh berutang, beda dari Open Play), jadi baris ini selalu berarti
 * "uangnya sudah ditarik". Membatalkan pesanan mengembalikan saldonya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Unit tempat pesanan diantar — pelanggan memesan dari layar kios
            // unit itu. restrictOnDelete supaya riwayat pesanan tidak yatim.
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['placed', 'preparing', 'delivered', 'cancelled'])->default('placed');
            $table->unsignedInteger('total_amount');
            // Siapa (staf) yang terakhir memajukan/membatalkan statusnya.
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Antrean staf membaca "yang belum selesai" terus-menerus.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_orders');
    }
};
