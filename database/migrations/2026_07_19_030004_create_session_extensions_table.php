<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('added_minutes');
            $table->unsignedInteger('amount');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_extensions');
    }
};
