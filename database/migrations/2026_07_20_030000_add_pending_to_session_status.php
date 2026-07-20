<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sesi yang dipesan lewat kios menunggu pembayaran sebelum berjalan.
 *
 * Nilainya HARUS sama persis dengan SessionStatus. ENUM MySQL menolak nilai
 * asing dengan "Data truncated" — dan seperti yang sudah pernah terjadi pada
 * device_alerts, kegagalannya tidak terlihat di layar mana pun kalau
 * penulisannya terjadi di dalam job antrean.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE rental_sessions MODIFY COLUMN status ENUM('pending','active','completed','voided') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("UPDATE rental_sessions SET status = 'voided' WHERE status = 'pending'");
        DB::statement("ALTER TABLE rental_sessions MODIFY COLUMN status ENUM('active','completed','voided') NOT NULL DEFAULT 'active'");
    }
};
