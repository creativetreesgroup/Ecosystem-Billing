<?php

use App\Domain\Billing\Actions\StartKioskOpenPlayAction;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Process;

/**
 * Satu pelanggan bisa memegang dua sesi Open Play (dua unit). Menghentikan
 * keduanya BERSAMAAN dulu bisa membuat salah satu melempar
 * InsufficientBalanceException: split saldo/kredit dihitung dari baca-basi saldo
 * sebelum baris pelanggan dikunci. Kini StopKioskOpenPlayAction mengunci baris
 * pelanggan lebih dulu, jadi kedua stop menghitung split dari saldo yang stabil.
 *
 * Dijalankan sebagai dua proses OS terpisah agar keduanya benar-benar berebut
 * row lock database yang sesungguhnya.
 */
test('two parallel Open Play stops for the same customer both succeed and stay ledger-consistent', function () {
    User::factory()->owner()->create(); // operator kios

    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 10_000); // saldo 10rb + credit-eligible

    $unitA = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $unitA->unitType->update(['hourly_rate' => 6_000]); // Rp 100/menit
    $unitB = Unit::factory()->create([
        'control_driver' => ControlDriver::Manual,
        'unit_type_id' => $unitA->unit_type_id,
    ]);

    $a = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $unitA);
    $b = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $unitB);

    // Masing-masing menagih ~Rp 8.000 (80 menit) → total ~16rb > saldo 10rb, jadi
    // yang kedua wajib menembus ke kredit. Itulah jendela rentan balapannya.
    $a->update(['started_at' => now()->subMinutes(80)]);
    $b->update(['started_at' => now()->subMinutes(80)]);

    // Env proses anak DISAMAKAN dengan lingkungan test (bukan .env produksi):
    // queue database, broadcast null, cache array — supaya efek samping pasca-
    // transaksi (notifikasi utang, broadcast) tidak menyentuh Redis/Reverb yang
    // tak ada di host dan mengaburkan hasil. Yang diuji adalah penagihannya.
    $env = [
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config('database.connections.mysql.host'),
        'DB_PORT' => config('database.connections.mysql.port'),
        'DB_DATABASE' => config('database.connections.mysql.database'),
        'DB_USERNAME' => config('database.connections.mysql.username'),
        'DB_PASSWORD' => config('database.connections.mysql.password'),
        'BROADCAST_CONNECTION' => 'null',
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array',
        'MAIL_MAILER' => 'array',
        'SESSION_DRIVER' => 'array',
    ];

    $results = Process::pool(function ($pool) use ($a, $b, $env) {
        $pool->command(['php', 'artisan', 'testing:attempt-stop-openplay', (string) $a->id])
            ->path(base_path())->env($env)->timeout(30);
        $pool->command(['php', 'artisan', 'testing:attempt-stop-openplay', (string) $b->id])
            ->path(base_path())->env($env)->timeout(30);
    })->wait();

    $output = trim($results[0]->output()).' '.trim($results[1]->output());
    $customer->refresh();

    // Tanda-tangan bug lama: split saldo/kredit dari baca basi → spend() menembus
    // lantai 0 → InsufficientBalanceException. Kunci baris pelanggan menghapusnya.
    expect($output)->not->toContain('InsufficientBalanceException');

    // Invarian sesungguhnya (DB, deterministik): kedua sesi tertagih & selesai,
    // buku besar konsisten, dan saldo minus tetap terjepit di plafon. Tanpa
    // kunci, sesi yang kalah balapan akan rollback dan tetap Active.
    expect(RentalSession::where('customer_id', $customer->id)->where('status', SessionStatus::Active)->count())->toBe(0)
        ->and(RentalSession::where('customer_id', $customer->id)->where('status', SessionStatus::Completed)->count())->toBe(2)
        ->and($customer->balance)->toBe($customer->ledgerBalance())
        ->and($customer->balance)->toBeLessThan(0)
        ->and($customer->balance)->toBeGreaterThanOrEqual(-50_000);
});
