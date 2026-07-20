<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Nomor HP sebagai identitas masuk: pelanggan outlet tidak punya
            // email, dan nomor HP adalah satu-satunya yang pasti mereka ingat
            // sambil berdiri di depan TV.
            $table->string('phone')->unique();

            // PIN, bukan kata sandi: diketik di HP sambil berdiri, kadang oleh
            // anak-anak. Disimpan ter-hash — PIN pendek justru LEBIH berbahaya
            // bila bocor mentah, karena orang memakai PIN yang sama di tempat lain.
            $table->string('pin_hash');

            // SALDO disimpan di kolom, dengan wallet_transactions sebagai buku
            // besarnya. Menghitung ulang dari buku besar tiap kali terlihat
            // lebih "benar", tapi berarti menjumlahkan seluruh riwayat pada
            // setiap layar kios — dan tetap tidak menjamin apa pun tanpa kunci
            // baris. Yang menjamin adalah: saldo HANYA berubah di dalam
            // transaksi yang mengunci barisnya, dan setiap perubahan menulis
            // satu baris buku besar. Ada test yang membuktikan keduanya cocok.
            $table->unsignedInteger('balance')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
