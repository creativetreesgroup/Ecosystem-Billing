<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buku besar dompet. Setiap perubahan saldo menulis satu baris di sini —
 * tanpa kecuali, termasuk koreksi.
 *
 * Alasannya bukan kerapian: saat pelanggan bilang "saldo saya kurang", satu
 * angka saldo tidak bisa menjawab apa pun. Yang menjawab adalah urutan
 * kejadiannya, dan itu hanya ada kalau setiap langkahnya dicatat saat terjadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['topup', 'spend', 'refund', 'adjustment']);

            // Bertanda: positif menambah, negatif mengurangi. Satu kolom
            // bertanda jauh lebih sulit salah dijumlahkan daripada dua kolom
            // masuk/keluar yang harus selalu diingat mana yang dikurangi.
            $table->integer('amount');

            // Saldo SETELAH baris ini. Disimpan, bukan dihitung: inilah yang
            // membuat sengketa bisa ditelusuri mundur tanpa menjumlahkan ulang
            // seluruh riwayat dan berharap tidak ada baris yang terlewat.
            $table->unsignedInteger('balance_after');

            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rental_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description')->nullable();

            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
