<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            // digabung dari link_sessions_to_customers: sesi kios milik pelanggan.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->enum('type', ['open', 'package']);
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            // Nullable: sesi kios yang menunggu pembayaran belum punya waktu
            // mulai. Memberinya waktu sebelum uangnya masuk berarti menagih
            // waktu yang belum dibeli.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // Nilainya HARUS sama persis dengan SessionStatus. 'pending' adalah
            // sesi kios yang menunggu pembayaran: belum berjalan, belum menagih.
            $table->enum('status', ['pending', 'active', 'completed', 'voided'])->default('active');
            $table->uuid('expiry_token');
            $table->unsignedInteger('base_amount')->default(0);
            $table->unsignedInteger('extra_amount')->default(0);
            // Diskon (V2): potongan yang dikurangkan dari total. Untuk paket
            // diisi saat mulai (dari voucher); untuk Open Play diisi saat berhenti
            // (persen atas tagihan akhir). voucher_code menyimpan kode antara mulai
            // & berhenti pada Open Play. Selalu tercatat di discount_redemptions.
            $table->unsignedInteger('discount_amount')->default(0);
            $table->string('voucher_code')->nullable();
            $table->unsignedInteger('total_amount')->nullable();
            // 'wallet' digabung dari add_wallet_to_payment_method_enums (bayar dari saldo).
            $table->enum('payment_method', ['cash', 'qris', 'transfer', 'wallet'])->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'status']);
            $table->index(['status', 'ends_at']);

            // Constraint kritikal: hanya satu sesi `active` per unit. MySQL tidak
            // punya partial unique index, jadi generated column ini jadi padanannya
            // — NULL boleh berulang, hanya baris `active` yang saling eksklusif.
            // Defense in depth bersama Unit::lockForUpdate() di StartSessionAction.
            $table->unsignedBigInteger('active_unit_id')
                ->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN unit_id ELSE NULL END");
            $table->unique('active_unit_id', 'uq_active_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_sessions');
    }
};
