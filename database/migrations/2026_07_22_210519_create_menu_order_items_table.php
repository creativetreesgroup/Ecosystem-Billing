<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris pesanan. Nama & harga DISALIN saat memesan (snapshot), bukan dibaca
 * ulang dari menu: harga menu boleh naik dan item boleh dihapus, tapi struk
 * pesanan yang sudah dibayar harus tetap menunjukkan apa yang dibeli dan
 * berapa harganya saat itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('line_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_order_items');
    }
};
