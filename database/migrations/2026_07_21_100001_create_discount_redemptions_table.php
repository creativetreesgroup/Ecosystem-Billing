<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu baris tiap kali diskon benar-benar dipakai. Inilah sumber kebenaran
     * kuota (dihitung di dalam kunci baris diskon saat menebus, supaya dua
     * transaksi tak bisa balapan menembus batas) dan jejak audit "diskon ini
     * memotong berapa, untuk siapa, di transaksi mana".
     */
    public function up(): void
    {
        Schema::create('discount_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rental_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            // Nominal potongan (rupiah) yang benar-benar diterapkan.
            $table->unsignedInteger('amount');
            $table->timestamps();

            $table->index(['discount_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
    }
};
