<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode sekali pakai yang dikirim ke WhatsApp pelanggan.
 *
 * Disimpan di tabel, bukan di cache: kode ini adalah kunci ke akun bersaldo,
 * jadi percobaan dan pemakaiannya harus meninggalkan jejak yang bisa diperiksa
 * saat ada sengketa. Cache yang hilang saat restart juga akan membuat kode
 * yang sedang dikirim mendadak tidak berlaku.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->index();

            // HASH, bukan kodenya. Dump database tidak boleh cukup untuk masuk
            // ke akun siapa pun — dan kode enam angka yang tersimpan mentah
            // persis seperti kata sandi yang ditulis di sticky note.
            $table->string('code_hash');

            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();

            // Untuk menelusuri biaya & kegagalan pengiriman per penyedia.
            $table->string('sent_via')->nullable();

            $table->timestamps();
            $table->index(['phone', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
