<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();

            // Kunci ditentukan sistem, bukan diketik operator — daftarnya ada
            // di IntegrationKey. Unique supaya tidak pernah ada dua baris untuk
            // integrasi yang sama, yang akan membuat "mana yang dipakai" jadi
            // pertanyaan tanpa jawaban.
            $table->string('key')->unique();

            $table->string('base_url')->nullable();

            // Kolom TEXT, bukan string: isinya ciphertext hasil cast
            // 'encrypted', yang jauh lebih panjang dari token aslinya.
            // Tidak pernah dikirim balik ke browser (lihat IntegrationForm).
            $table->text('token')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
