<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item yang bisa dipesan. Tanpa stok: habis = dimatikan owner (is_active),
 * jadi tak ada hitungan stok yang harus selalu benar untuk bisa jualan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            // restrictOnDelete: kategori berisi item tidak boleh hilang diam-diam
            // dan membawa itemnya (pola sama dengan packages -> unit_types).
            $table->foreignId('menu_category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('price');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
