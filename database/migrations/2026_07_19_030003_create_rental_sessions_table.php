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
            $table->string('customer_name')->nullable();
            $table->enum('type', ['open', 'package']);
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->enum('status', ['active', 'completed', 'voided'])->default('active');
            $table->uuid('expiry_token');
            $table->unsignedInteger('base_amount')->default(0);
            $table->unsignedInteger('extra_amount')->default(0);
            $table->unsignedInteger('total_amount')->nullable();
            $table->enum('payment_method', ['cash', 'qris', 'transfer'])->nullable();
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
