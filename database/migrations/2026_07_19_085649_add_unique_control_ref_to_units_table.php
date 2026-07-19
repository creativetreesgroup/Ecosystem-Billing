<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu perangkat fisik hanya boleh dipegang satu unit.
     *
     * Tanpa ini dua unit bisa menunjuk `control_ref` yang sama (mis. satu TV
     * fisik yang muncul sebagai dua entity berbeda di Home Assistant, atau
     * sekadar salah pilih saat menambah unit). Akibatnya nyata dan merugikan:
     * kasir menutup sesi di unit B ikut MEMATIKAN TV unit A yang pelanggannya
     * masih bermain dan masih ditagih.
     *
     * MySQL mengizinkan NULL berulang di unique index, jadi unit ber-driver
     * manual (control_ref NULL) tidak terpengaruh sama sekali. Di-scope ke
     * outlet_id karena tiap outlet punya instance Home Assistant/broker
     * sendiri — control_ref yang sama di outlet berbeda adalah perangkat
     * berbeda (fondasi V2, lihat DECISIONS.md Fase 0).
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->unique(['outlet_id', 'control_ref'], 'uq_unit_control_ref');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropUnique('uq_unit_control_ref');
        });
    }
};
