<?php

use App\Domain\Billing\Actions\StartKioskOpenPlayAction;
use App\Domain\Billing\Actions\StopKioskOpenPlayAction;
use App\Domain\Billing\OpenPlay;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\SalesSummary;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Exceptions\SessionTooShortException;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Domain\Wallet\Exceptions\CreditCeilingReachedException;
use App\Domain\Wallet\Exceptions\CreditNotAllowedException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;

/**
 * Open Play boleh membuat saldo MINUS — satu-satunya tempat di seluruh sistem
 * yang boleh. Karena itu ia diuji paling ketat: penjaga biasa harus tetap
 * utuh, minusnya harus terbatas, dan celah akun-buang harus tertutup.
 */
beforeEach(function () {
    User::factory()->owner()->create();
    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    // Tarif Rp 6.000/jam = Rp 100/menit → mudah dihitung di test.
    $this->unit->unitType->update(['hourly_rate' => 6_000]);
    $this->wallet = app(Wallet::class);
});

/**
 * Penjaga inti tidak boleh dilonggarkan: spend() biasa TETAP menolak saldo
 * minus, apa pun yang ditambahkan untuk Open Play.
 */
test('the ordinary spend guard still refuses to go negative', function () {
    $customer = Customer::factory()->withBalance(3_000)->create();

    expect(fn () => $this->wallet->spend($customer, 5_000))
        ->toThrow(InsufficientBalanceException::class);

    expect($customer->fresh()->balance)->toBe(3_000);
});

/**
 * Jalur kredit boleh minus — TAPI hanya sampai plafon, dan berhenti di sana.
 */
test('credit spending goes negative but stops at the ceiling', function () {
    $customer = Customer::factory()->withBalance(0)->create();

    $this->wallet->spendOnCredit($customer, OpenPlay::CREDIT_CEILING, OpenPlay::CREDIT_CEILING);
    expect($customer->fresh()->balance)->toBe(-OpenPlay::CREDIT_CEILING);

    // Satu rupiah lagi melewati plafon → ditolak, saldo tak berubah.
    expect(fn () => $this->wallet->spendOnCredit($customer->fresh(), 1, OpenPlay::CREDIT_CEILING))
        ->toThrow(CreditCeilingReachedException::class);

    expect($customer->fresh()->balance)->toBe(-OpenPlay::CREDIT_CEILING);
});

/**
 * Celah akun-buang: akun baru bersaldo nol yang belum pernah top-up TIDAK boleh
 * memulai Open Play — kalau boleh, ia berutang dari detik pertama lalu bisa
 * ditinggalkan.
 */
test('a fresh zero-balance account cannot start Open Play on credit', function () {
    $customer = Customer::factory()->withBalance(0)->create();

    expect(fn () => app(StartKioskOpenPlayAction::class)->handle($customer, $this->unit))
        ->toThrow(CreditNotAllowedException::class);

    expect($this->unit->fresh()->activeSession)->toBeNull();
});

test('an account that has topped up before may start Open Play even at zero', function () {
    $customer = Customer::factory()->withBalance(0)->create();
    $this->wallet->topUp($customer, 20_000);
    $this->wallet->spend($customer->fresh(), 20_000); // habiskan lagi → saldo 0 tapi pernah top-up

    $session = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $this->unit);

    expect($session->type)->toBe(SessionType::Open)
        ->and($session->status)->toBe(SessionStatus::Active)
        ->and($this->unit->fresh()->activeSession)->not->toBeNull();
});

/**
 * Menit pertama tetap ditagih: sesi tidak bisa dihentikan sebelum 60 detik.
 */
test('an Open Play session cannot be stopped in its first minute', function () {
    $customer = Customer::factory()->withBalance(20_000)->create();
    $session = app(StartKioskOpenPlayAction::class)->handle($customer, $this->unit);

    expect(fn () => app(StopKioskOpenPlayAction::class)->handle($session))
        ->toThrow(SessionTooShortException::class);

    expect($this->unit->fresh()->activeSession)->not->toBeNull();
});

/**
 * Berhenti dengan saldo cukup: dipotong biasa, saldo tetap ≥ 0, tidak ada utang.
 */
test('stopping with enough balance just deducts, no debt', function () {
    $customer = Customer::factory()->withBalance(20_000)->create();
    $session = app(StartKioskOpenPlayAction::class)->handle($customer, $this->unit);
    $session->update(['started_at' => now()->subMinutes(30)]); // 30 menit → Rp 3.000

    $done = app(StopKioskOpenPlayAction::class)->handle($session);

    expect($done->status)->toBe(SessionStatus::Completed)
        ->and($done->total_amount)->toBe(3_000)
        ->and($customer->fresh()->balance)->toBe(17_000);
});

/**
 * Inti permintaan pemilik: saldo dipotong dulu sampai nol, sisanya jadi MINUS.
 * Rp 5.000 saldo, main 1 jam (Rp 6.000) → tagihan 6.000, saldo 5.000 − 6.000 =
 * −Rp 1.000.
 */
test('stopping without enough balance leaves the balance negative', function () {
    $customer = Customer::factory()->withBalance(5_000)->create();
    $this->wallet->topUp($customer, 0 + 5_000); // jadikan credit-eligible (ada top-up)
    $customer->update(['balance' => 5_000]);     // kembalikan saldo ke 5.000 untuk kejelasan

    $session = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $this->unit);
    $session->update(['started_at' => now()->subMinutes(60)]); // 60 menit → Rp 6.000

    $done = app(StopKioskOpenPlayAction::class)->handle($session);

    expect($done->total_amount)->toBe(6_000)
        ->and($customer->fresh()->balance)->toBe(-1_000);
});

/**
 * Jasa terpakai TETAP pendapatan meski utangnya belum dilunasi: uangnya diakui
 * saat sesi berjalan (metode Saldo), bukan menunggu saldo minus dibayar.
 */
test('a played Open Play session is revenue even while the debt is unpaid', function () {
    $customer = Customer::factory()->withBalance(0)->create();
    $this->wallet->topUp($customer, 0 + 1_000);
    $customer->update(['balance' => 0]);

    $session = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $this->unit);
    $session->update(['started_at' => now()->subMinutes(60)]);

    $done = app(StopKioskOpenPlayAction::class)->handle($session);

    expect($done->payments()->sole())
        ->status->toBe(PaymentStatus::Paid)
        ->method->toBe(PaymentMethod::Wallet);

    // Saldo pelanggan minus (utang), tapi pendapatannya sudah tercatat.
    expect($customer->fresh()->balance)->toBeLessThan(0);

    $hari = now(SalesSummary::timezone())->toDateString();
    expect((new SalesSummary($hari, $hari))->totalRevenue())->toBe(6_000);
});

/**
 * REGRESI: menghentikan sesi saat saldo SUDAH minus (mis. satu pelanggan memakai
 * dua unit; unit pertama sudah menariknya ke −50k) tidak boleh melempar. Dulu
 * `min(credit, 50k)` menembus lantai → CreditCeilingReachedException → sesi macet
 * aktif selamanya & job backstop crash. Kini dijepit ke headroom (0 di lantai).
 */
test('stopping a second session when the balance is already at the floor stays clamped, no throw', function () {
    $customer = Customer::factory()->create();
    $this->wallet->topUp($customer, 5_000); // credit-eligible + saldo 5.000

    $unitB = Unit::factory()->create([
        'control_driver' => ControlDriver::Manual,
        'unit_type_id' => $this->unit->unit_type_id, // tarif sama (Rp 100/menit)
    ]);

    $a = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $this->unit);
    $b = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $unitB);

    // A main lama → menagih jauh melebihi saldo → saldo mentok −50.000.
    $a->update(['started_at' => now()->subMinutes(600)]); // Rp 60.000
    app(StopKioskOpenPlayAction::class)->handle($a);
    expect($customer->fresh()->balance)->toBe(-50_000);

    // B di-stop saat saldo SUDAH −50.000: tidak melempar, tetap terjepit.
    $b->update(['started_at' => now()->subMinutes(600)]);
    $doneB = app(StopKioskOpenPlayAction::class)->handle($b);

    expect($doneB->status)->toBe(SessionStatus::Completed)
        ->and($customer->fresh()->balance)->toBe(-50_000)
        ->and($customer->fresh()->ledgerBalance())->toBe(-50_000);
});

/**
 * REGRESI: pelanggan yang saldonya sudah di/di bawah lantai plafon (berutang
 * penuh) tidak boleh memulai Open Play lagi — hanya menambah tagihan yang tak
 * bisa ditarik.
 */
test('a customer already at the credit floor cannot start Open Play again', function () {
    $customer = Customer::factory()->create();
    $this->wallet->topUp($customer, 1_000); // credit-eligible
    $this->wallet->adjust($customer, -51_000, 'Denda', User::factory()->owner()->create(), allowNegative: true);
    expect($customer->fresh()->balance)->toBe(-50_000);

    expect(fn () => app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $this->unit))
        ->toThrow(CreditNotAllowedException::class);
});
