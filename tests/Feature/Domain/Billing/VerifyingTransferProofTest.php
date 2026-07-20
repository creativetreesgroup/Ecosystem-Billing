<?php

use App\Domain\Billing\Actions\RejectPaymentAction;
use App\Domain\Billing\Actions\VerifyPaymentAction;
use App\Domain\Billing\Exceptions\IllegalPaymentTransitionException;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\SalesSummary;
use App\Models\Payment;
use App\Models\RentalSession;
use App\Models\User;

beforeEach(function () {
    $this->kasir = User::factory()->create();
    $this->session = RentalSession::factory()->completedAwaitingPayment()->create(['total_amount' => 75_000]);
    $this->payment = $this->session->payments()->sole();
});

test('verifying a proof turns it into revenue and records who is accountable', function () {
    $verified = app(VerifyPaymentAction::class)->handle($this->payment, $this->kasir);

    expect($verified)
        ->status->toBe(PaymentStatus::Paid)
        ->verified_by->toBe($this->kasir->id)
        ->verified_at->not->toBeNull();

    $hari = now(SalesSummary::timezone())->toDateString();
    expect((new SalesSummary($hari, $hari))->totalRevenue())->toBe(75_000);
});

/**
 * Dua kasir bisa membuka daftar yang sama dan menekan Terima pada baris yang
 * sama. Tanpa penjaga ini keduanya lolos dan pemasukan tercatat ganda — uang
 * bertambah di laporan tanpa ada uang tambahan yang benar-benar masuk.
 */
test('the same proof cannot be verified twice', function () {
    app(VerifyPaymentAction::class)->handle($this->payment, $this->kasir);

    expect(fn () => app(VerifyPaymentAction::class)->handle($this->payment->fresh(), $this->kasir))
        ->toThrow(IllegalPaymentTransitionException::class);

    $hari = now(SalesSummary::timezone())->toDateString();
    expect((new SalesSummary($hari, $hari))->totalRevenue())->toBe(75_000);
});

test('a rejection must carry a reason, and never becomes revenue', function () {
    $rejected = app(RejectPaymentAction::class)->handle($this->payment, $this->kasir, 'Nominal transfer Rp 50.000, seharusnya Rp 75.000.');

    expect($rejected)
        ->status->toBe(PaymentStatus::Rejected)
        ->rejection_reason->toBe('Nominal transfer Rp 50.000, seharusnya Rp 75.000.')
        ->verified_by->toBe($this->kasir->id);

    $hari = now(SalesSummary::timezone())->toDateString();
    expect((new SalesSummary($hari, $hari))->totalRevenue())->toBe(0);
});

test('an empty reason is refused', function () {
    expect(fn () => app(RejectPaymentAction::class)->handle($this->payment, $this->kasir, '   '))
        ->toThrow(InvalidArgumentException::class);

    expect($this->payment->fresh()->status)->toBe(PaymentStatus::AwaitingVerification);
});

/**
 * Membatalkan uang yang SUDAH diterima bukan urusan penolakan bukti — itu
 * void sesi, yang punya jejak auditnya sendiri. Menyediakan dua jalan menuju
 * hasil yang sama membuat laporan punya dua cerita berbeda.
 */
test('money already taken cannot be undone by rejecting the proof', function () {
    app(VerifyPaymentAction::class)->handle($this->payment, $this->kasir);

    expect(fn () => app(RejectPaymentAction::class)->handle($this->payment->fresh(), $this->kasir, 'Berubah pikiran'))
        ->toThrow(IllegalPaymentTransitionException::class);
});

test('a cash payment taken by the cashier is not something to verify', function () {
    $cash = Payment::factory()->create(['status' => PaymentStatus::Paid]);

    expect(fn () => app(VerifyPaymentAction::class)->handle($cash, $this->kasir))
        ->toThrow(IllegalPaymentTransitionException::class);
});
