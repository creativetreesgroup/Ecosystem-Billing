<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menyeragamkan bentuk kolom `value`.
 *
 * Pengaturan lama disimpan sebagai {"minutes": 5} — bentuk yang hanya masuk
 * akal untuk pengaturan berupa waktu, dan itulah alasan nomor rekening tidak
 * pernah bisa disimpan di sini. Semua kini {"value": ...}, satu bentuk untuk
 * semua tipe, sehingga form dan tabelnya tidak perlu tahu kunci mana yang
 * sedang ditampilkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('settings')->get() as $setting) {
            $value = json_decode($setting->value, true);

            if (! is_array($value) || array_key_exists('value', $value)) {
                continue;
            }

            DB::table('settings')
                ->where('id', $setting->id)
                ->update(['value' => json_encode(['value' => reset($value)])]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('settings')->get() as $setting) {
            $value = json_decode($setting->value, true);

            if (! is_array($value) || ! array_key_exists('value', $value)) {
                continue;
            }

            DB::table('settings')
                ->where('id', $setting->id)
                ->update(['value' => json_encode(['minutes' => $value['value']])]);
        }
    }
};
