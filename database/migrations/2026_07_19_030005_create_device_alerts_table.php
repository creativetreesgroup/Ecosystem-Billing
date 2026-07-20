<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            // Nilainya HARUS sama persis dengan DeviceAlertType. ENUM di MySQL
            // menolak nilai asing dengan "Data truncated" — dan karena alert
            // dibuat dari dalam job antrean, kegagalannya tidak terlihat di
            // layar mana pun, cuma menumpuk di failed_jobs.
            $table->enum('type', ['power_off_failed', 'power_on_failed', 'device_offline', 'state_mismatch']);
            $table->string('message');
            $table->enum('status', ['open', 'acknowledged'])->default('open');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_alerts');
    }
};
