<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sesi kios menunggu pembayaran sebelum berjalan, jadi ia belum punya waktu
 * mulai. Memberinya waktu sebelum uangnya masuk berarti menagih waktu yang
 * belum dibeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE rental_sessions MODIFY COLUMN started_at TIMESTAMP NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE rental_sessions SET started_at = created_at WHERE started_at IS NULL');
        DB::statement('ALTER TABLE rental_sessions MODIFY COLUMN started_at TIMESTAMP NOT NULL');
    }
};
