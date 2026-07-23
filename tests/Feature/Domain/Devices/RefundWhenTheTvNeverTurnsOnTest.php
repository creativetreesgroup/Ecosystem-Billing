<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceAlertType;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\Jobs\VerifyUnitPoweredOnJob;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Domain\Wallet\WalletTransactionType;
use App\Models\Customer;
use App\Models\DeviceAlert;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Kasus "saldo terpotong tapi pelanggan tidak dapat apa-apa" yang TIDAK bisa
 * ditolong transaksi database: sesinya sah dan sudah ter-commit, tapi TV-nya
 * tak pernah menyala. Selama ini sistem hanya membuat alert untuk staf —
 * uangnya tetap tertahan dan jamnya tetap berjalan di layar gelap.
 *
 * Sekarang, kalau perangkatnya sendiri MEMASTIKAN dirinya tidak menyala setelah
 * semua percobaan habis, sesinya di-void dan saldonya kembali otomatis.
 */
beforeEach(function () {
    config(['services.home_assistant.base_url' => 'http://ha.test', 'services.home_assistant.token' => 'secret-token']);

    User::factory()->owner()->create();
    $this->customer = Customer::factory()->create(['balance' => 100_000]);
    $this->unit = Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.tv_ps01',
    ]);
    $this->package = Package::factory()->for($this->unit->unitType)->create(['price' => 25_000, 'duration_minutes' => 60]);

    // HANYA endpoint perintah. Stub Http bersifat menumpuk dan yang terdaftar
    // lebih dulu menang, jadi catch-all di sini akan membajak /api/states/*
    // yang dipasang tiap test — dan justru state itulah yang diuji.
    Http::fake(['ha.test/api/services/*' => Http::response([])]);
});

/** Perangkat menjawab: saya standby. Bukan tebakan — pelanggan memang tidak bermain. */
function tvReportsItIsOff(): void
{
    Http::fake(['ha.test/api/states/*' => Http::response(['state' => 'off'])]);
}

/** Percobaan terakhir; sebelumnya sudah tiga kali gagal. */
function lastPowerOnAttempt(Unit $unit): void
{
    (new VerifyUnitPoweredOnJob($unit->id, attempt: 4))->handle(app(DeviceManager::class));
}

test('a session whose TV is confirmed off is voided and the money returned', function () {
    $session = app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $this->package);
    expect($this->customer->fresh()->balance)->toBe(75_000);

    tvReportsItIsOff();

    lastPowerOnAttempt($this->unit);

    expect($session->fresh()->status)->toBe(SessionStatus::Voided)
        ->and($this->customer->fresh()->balance)->toBe(100_000)
        ->and($this->customer->walletTransactions()->latest('id')->first()->type)->toBe(WalletTransactionType::Refund);
});

/** Staf tetap harus tahu TV-nya rusak — pengembalian saldo bukan pengganti alert. */
test('the staff alert is raised even when the money is returned automatically', function () {
    app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $this->package);
    tvReportsItIsOff();

    lastPowerOnAttempt($this->unit);

    expect(DeviceAlert::query()
        ->where('unit_id', $this->unit->id)
        ->where('type', DeviceAlertType::PowerOnFailed)
        ->exists())->toBeTrue();
});

/**
 * KRITIS: jaringan putus BUKAN bukti TV mati — pelanggan bisa saja sedang asyik
 * bermain sementara Home Assistant-nya yang tak terjangkau. Mengembalikan saldo
 * di sini berarti membagikan sesi gratis. Tidak tahu = jangan sentuh uangnya,
 * cukup panggil manusia. (Sama seperti aturan Batalkan saat gateway QRIS mati.)
 */
test('an unreachable device never triggers a refund, the session keeps running', function () {
    $session = app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $this->package);

    Http::fake(['ha.test/*' => Http::failedConnection()]);

    lastPowerOnAttempt($this->unit);

    expect($session->fresh()->status)->toBe(SessionStatus::Active)
        ->and($this->customer->fresh()->balance)->toBe(75_000)
        ->and(DeviceAlert::where('unit_id', $this->unit->id)->exists())->toBeTrue();
});

/**
 * Sesi kasir dibayar tunai — uangnya di laci, bukan di dompet digital. Void
 * otomatis di sini menghapusnya dari pendapatan tanpa ada uang yang benar-benar
 * kembali ke siapa pun; keputusannya milik kasir, bukan job.
 */
test('a cash session is never voided automatically, only reported', function () {
    $session = app(StartSessionAction::class)->handle($this->unit, User::factory()->create(), SessionType::Open);

    tvReportsItIsOff();

    lastPowerOnAttempt($this->unit);

    expect($session->fresh()->status)->toBe(SessionStatus::Active)
        ->and(DeviceAlert::where('unit_id', $this->unit->id)->exists())->toBeTrue();
});

/** Percobaan yang belum habis hanya mencoba lagi — jangan buru-buru membatalkan sesi. */
test('an earlier attempt retries instead of refunding', function () {
    $session = app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $this->package);
    tvReportsItIsOff();

    (new VerifyUnitPoweredOnJob($this->unit->id, attempt: 1))->handle(app(DeviceManager::class));

    expect($session->fresh()->status)->toBe(SessionStatus::Active)
        ->and($this->customer->fresh()->balance)->toBe(75_000);
});
