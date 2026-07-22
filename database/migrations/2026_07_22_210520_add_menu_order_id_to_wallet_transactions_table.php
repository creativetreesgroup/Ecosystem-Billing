<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak buku besar untuk pesanan jajanan — sejajar dengan payment_id &
 * rental_session_id yang sudah ada. Tanpa ini, potongan saldo untuk pesanan
 * tidak bisa ditelusuri balik ke pesanannya saat ada sengketa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->foreignId('menu_order_id')->nullable()->after('rental_session_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('menu_order_id');
        });
    }
};
