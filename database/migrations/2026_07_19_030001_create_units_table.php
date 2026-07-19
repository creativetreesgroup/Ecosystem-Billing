<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_type_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->enum('control_driver', ['home_assistant', 'tasmota', 'manual']);
            $table->string('control_ref')->nullable();
            $table->string('tv_mac')->nullable();
            $table->json('capabilities')->nullable();
            $table->enum('power_state', ['on', 'standby', 'unreachable', 'unknown'])->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
