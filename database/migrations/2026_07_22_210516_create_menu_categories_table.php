<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kategori menu jajanan: Snack, Makanan, Minuman, dst. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_categories');
    }
};
