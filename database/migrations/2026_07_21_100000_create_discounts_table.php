<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            // Kode voucher (diketik pelanggan/kasir) — null untuk promo otomatis.
            // Unik supaya satu kode tak pernah menunjuk dua diskon.
            $table->string('code')->nullable()->unique();
            $table->string('name');
            // percentage | fixed — cara menghitung potongan dari `value`.
            $table->enum('type', ['percentage', 'fixed']);
            // voucher (butuh kode) | promo (otomatis, berbasis aturan).
            $table->enum('source', ['voucher', 'promo']);
            // Persen (1–100) untuk percentage, rupiah untuk fixed.
            $table->unsignedInteger('value');
            // Permukaan yang boleh didiskon: package | topup | open_play.
            $table->json('targets');
            // Jendela berlaku (null = tak dibatasi waktu).
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // Kuota pemakaian (null = tak terbatas).
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_customer')->nullable();
            // Minimal nominal transaksi supaya diskon berlaku (0 = tanpa syarat).
            $table->unsignedInteger('min_amount')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['source', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
