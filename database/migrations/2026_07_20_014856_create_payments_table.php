<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // Sengaja BUKAN satu-ke-satu: satu sesi bisa punya beberapa upaya
            // bayar. QRIS kedaluwarsa lalu pelanggan membayar tunai, atau bukti
            // transfer ditolak lalu diunggah ulang. Menimpa barisnya akan
            // menghapus jejak percobaan yang gagal — justru jejak itu yang
            // dicari saat ada sengketa nominal.
            $table->foreignId('rental_session_id')->constrained()->cascadeOnDelete();

            $table->enum('method', ['cash', 'qris', 'transfer']);
            $table->enum('status', ['pending', 'awaiting_verification', 'paid', 'rejected', 'expired']);
            $table->unsignedInteger('amount');

            // Rujukan dari luar: order_id Midtrans untuk QRIS, atau catatan bank
            // untuk transfer. Unique supaya satu transaksi gateway tidak pernah
            // tercatat dua kali sebagai dua pemasukan.
            $table->string('reference')->nullable()->unique();

            // Bukti transfer yang diunggah pelanggan. Disimpan di disk PRIVAT,
            // bukan public: isinya nama & nomor rekening orang.
            $table->string('proof_path')->nullable();

            // Siapa kasir yang memastikan uangnya benar-benar masuk. Kolom ini
            // yang membuat "sudah diverifikasi" punya nama, bukan sekadar
            // status yang berubah sendiri.
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            // Laporan menyaring "yang benar-benar lunas" pada rentang waktu.
            $table->index(['status', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
