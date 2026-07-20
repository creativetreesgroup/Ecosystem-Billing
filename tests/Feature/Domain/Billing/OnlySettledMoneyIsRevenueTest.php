<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\SalesSummary;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionType;
use App\Models\Payment;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Aturan paling mahal di seluruh sistem: yang boleh disebut PENDAPATAN hanya
 * uang yang benar-benar terbukti diterima.
 *
 * Selama kasir memegang uang di depan, "sesi selesai" dan "sudah dibayar"
 * adalah hal yang sama. Begitu pelanggan bisa membayar sendiri — QRIS lewat
 * gateway, transfer dengan bukti yang harus diperiksa — keduanya berpisah:
 * sesi bisa berakhir sementara uangnya belum masuk sama sekali. Menghitungnya
 * membuat laporan berbohong tepat pada angka yang dipakai menutup kas, dan
 * selisihnya baru ketahuan berhari-hari kemudian.
 */
function hariIni(): SalesSummary
{
    $hari = now(SalesSummary::timezone())->toDateString();

    return new SalesSummary($hari, $hari);
}

test('a session closed by the cashier counts immediately', function () {
    $kasir = User::factory()->create();
    $unit = Unit::factory()->create();

    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);
    $selesai = app(CompleteSessionAction::class)->handle($session, PaymentMethod::Cash, verifiedBy: $kasir);

    expect($selesai->payments()->sole())
        ->status->toBe(PaymentStatus::Paid)
        ->verified_by->toBe($kasir->id);

    expect(hariIni()->totalRevenue())->toBe((int) $selesai->total_amount);
});

test('a finished session whose transfer proof is still unchecked is not revenue yet', function () {
    RentalSession::factory()->completedAwaitingPayment()->create(['total_amount' => 50_000]);

    expect(hariIni())
        ->totalRevenue()->toBe(0)
        ->totalSessions()->toBe(0);
});

test('the same session becomes revenue the moment the cashier verifies it', function () {
    $kasir = User::factory()->create();
    $session = RentalSession::factory()->completedAwaitingPayment()->create(['total_amount' => 50_000]);

    expect(hariIni()->totalRevenue())->toBe(0);

    $session->payments()->sole()->update([
        'status' => PaymentStatus::Paid,
        'verified_by' => $kasir->id,
        'verified_at' => now(),
    ]);

    expect(hariIni()->totalRevenue())->toBe(50_000);
});

test('a rejected proof never turns into revenue', function () {
    $session = RentalSession::factory()->completedAwaitingPayment()->create(['total_amount' => 50_000]);

    $session->payments()->sole()->update([
        'status' => PaymentStatus::Rejected,
        'rejection_reason' => 'Nominal tidak cocok.',
    ]);

    expect(hariIni()->totalRevenue())->toBe(0);
});

/**
 * Satu transaksi gateway tidak boleh pernah tercatat dua kali sebagai dua
 * pemasukan — itu menggandakan pendapatan tanpa ada uang tambahan yang masuk.
 */
test('one gateway transaction can never be recorded twice', function () {
    $session = RentalSession::factory()->completed()->create();

    Payment::factory()->qrisPending()->create([
        'rental_session_id' => $session->id,
        'reference' => 'ORDER-DOBEL-1',
    ]);

    expect(fn () => Payment::factory()->qrisPending()->create([
        'rental_session_id' => $session->id,
        'reference' => 'ORDER-DOBEL-1',
    ]))->toThrow(UniqueConstraintViolationException::class);
});
