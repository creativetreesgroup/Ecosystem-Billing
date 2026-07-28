<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Endpoint kesehatan untuk orkestrator, monitoring, dan teknisi.
 *
 * Dipisah menjadi dua karena keduanya menjawab pertanyaan berbeda, dan
 * menggabungkannya adalah kesalahan operasional yang mahal:
 *
 *   /health  — "apakah proses ini hidup?" TIDAK menyentuh dependensi apa pun.
 *              Kalau ikut mengecek database, MySQL yang sedang restart akan
 *              membuat orkestrator MEMBUNUH aplikasi yang sebenarnya sehat,
 *              lalu menyalakannya lagi ke database yang masih sama-sama belum
 *              siap — restart loop di tengah jam sibuk outlet.
 *
 *   /ready   — "apakah proses ini sanggup melayani permintaan?" Mengecek DB,
 *              Redis, dan storage. Yang gagal di sini pantas dikeluarkan dari
 *              rotasi, bukan dibunuh.
 *
 * Keduanya sengaja TIDAK membocorkan pesan exception, host, atau connection
 * string: endpoint ini tidak berautentikasi, dan detail kegagalan internal
 * adalah peta jaringan gratis bagi siapa pun yang bisa menjangkau port 80.
 */
class HealthController
{
    /**
     * Liveness. Selalu 200 selama PHP masih bisa mengeksekusi permintaan.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Readiness. 200 bila seluruh dependensi wajib menjawab, 503 bila tidak.
     */
    public function ready(): JsonResponse
    {
        /** @var array<string, bool> $checks */
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo()),
            'redis' => $this->check(fn () => Redis::connection()->command('ping')),
            'storage' => $this->check(function (): void {
                // Menulis sungguhan, bukan sekadar is_writable(): disk penuh dan
                // mount yang berubah read-only baru ketahuan saat benar-benar menulis.
                $probe = 'health/.readiness-probe';
                Storage::disk('local')->put($probe, (string) now()->getTimestamp());
                Storage::disk('local')->delete($probe);
            }),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ready' : 'not_ready',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * Menjalankan satu pemeriksaan dan menerjemahkannya menjadi boolean.
     *
     * Throwable ditangkap seluruhnya dan isinya dibuang secara sengaja —
     * pemanggil hanya berhak tahu "gagal", bukan mengapa.
     */
    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
