<?php

use App\Domain\Billing\Actions\OpenKioskCheckoutAction;
use App\Domain\Billing\Actions\SettleQrisPaymentAction;
use App\Domain\Billing\Actions\VerifyPaymentAction;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\IntegrationKey;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\Exceptions\UnitAlreadyActiveException;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\Integration;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Aturan yang membuat kios tanpa kasir tetap aman: UANG DULU, WAKTU BELAKANGAN.
 *
 * Di panel kasir, kasir memegang uangnya di depan sehingga sesi boleh langsung
 * berjalan. Di kios tidak ada penjaga — kalau sesi dimulai lebih dulu dan
 * pembayarannya menyusul, siapa pun bisa main berjam-jam lalu pergi, dan
 * sistem tidak punya cara apa pun menagihnya.
 */
beforeEach(function () {
    Integration::query()->where('key', IntegrationKey::Midtrans)->delete();
    Integration::factory()->create([
        'key' => IntegrationKey::Midtrans,
        'base_url' => 'https://api.sandbox.midtrans.com',
        'token' => 'SB-Mid-server-uji',
        'is_active' => true,
    ]);

    User::factory()->owner()->create();
    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $this->package = Package::factory()->for($this->unit->unitType)->create(['price' => 25_000, 'duration_minutes' => 60]);

    Http::fake([
        'api.sandbox.midtrans.com/v2/charge' => Http::response([
            'actions' => [['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/qr/abc']],
        ]),
    ]);
});

test('a kiosk order creates a bill, not a running session', function () {
    ['payment' => $payment] = app(OpenKioskCheckoutAction::class)
        ->handle($this->unit, $this->package, PaymentMethod::Qris, 'Budi');

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBe(25_000);

    $session = $payment->rentalSession;

    expect($session->status)->toBe(SessionStatus::Pending)
        ->and($session->started_at)->toBeNull()
        // Yang paling penting: unit ini masih dianggap KOSONG. Selama belum
        // dibayar, kasir harus tetap bisa menyewakannya ke orang lain.
        ->and($this->unit->fresh()->activeSession)->toBeNull();
});

test('the session only starts once the gateway confirms the money', function () {
    ['payment' => $payment] = app(OpenKioskCheckoutAction::class)
        ->handle($this->unit, $this->package, PaymentMethod::Qris, 'Budi');

    Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'transaction_status' => 'settlement',
        'gross_amount' => '25000.00',
    ])]);

    app(SettleQrisPaymentAction::class)->handle($payment);

    $session = $payment->rentalSession->fresh();

    expect($session->status)->toBe(SessionStatus::Active)
        ->and($session->started_at)->not->toBeNull()
        ->and($session->started_at->diffInMinutes($session->ends_at))->toEqualWithDelta(60, 1)
        ->and($this->unit->fresh()->activeSession)->not->toBeNull();
});

test('an unpaid kiosk order never starts the session', function () {
    ['payment' => $payment] = app(OpenKioskCheckoutAction::class)
        ->handle($this->unit, $this->package, PaymentMethod::Qris, 'Budi');

    Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'transaction_status' => 'expire',
        'gross_amount' => '25000.00',
    ])]);

    app(SettleQrisPaymentAction::class)->handle($payment);

    expect($payment->rentalSession->fresh()->status)->toBe(SessionStatus::Pending)
        ->and($this->unit->fresh()->activeSession)->toBeNull();
});

test('a transfer starts the session the moment the cashier accepts the proof', function () {
    ['payment' => $payment] = app(OpenKioskCheckoutAction::class)
        ->handle($this->unit, $this->package, PaymentMethod::Transfer, 'Rina');

    $payment->update(['status' => PaymentStatus::AwaitingVerification, 'proof_path' => 'payment-proofs/uji.jpg']);

    expect($this->unit->fresh()->activeSession)->toBeNull();

    app(VerifyPaymentAction::class)->handle($payment->fresh(), User::factory()->create());

    expect($payment->rentalSession->fresh()->status)->toBe(SessionStatus::Active)
        ->and($this->unit->fresh()->activeSession)->not->toBeNull();
});

/**
 * Dua orang memindai QR unit yang sama. Tanpa penjaga ini keduanya membayar
 * untuk satu unit — uang kedua tidak punya tempat duduk, dan mengembalikannya
 * jauh lebih mahal daripada mencegahnya.
 */
test('a second order for the same unit cancels the first unpaid one', function () {
    ['payment' => $pertama] = app(OpenKioskCheckoutAction::class)
        ->handle($this->unit, $this->package, PaymentMethod::Qris, 'Budi');

    ['payment' => $kedua] = app(OpenKioskCheckoutAction::class)
        ->handle($this->unit, $this->package, PaymentMethod::Qris, 'Rina');

    expect($pertama->fresh()->status)->toBe(PaymentStatus::Expired)
        ->and($pertama->rentalSession->fresh()->status)->toBe(SessionStatus::Voided)
        ->and($kedua->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('a unit already in use cannot be ordered from the kiosk', function () {
    app(StartSessionAction::class)->handle($this->unit, User::factory()->create(), SessionType::Open);

    expect(fn () => app(OpenKioskCheckoutAction::class)->handle($this->unit, $this->package, PaymentMethod::Qris))
        ->toThrow(UnitAlreadyActiveException::class);
});

/**
 * Tidak ada cara memastikan uang tunai berpindah tanpa manusia yang
 * menerimanya — menawarkannya di kios berarti menjanjikan jaminan yang tidak
 * bisa ditepati sistem.
 */
test('cash is never offered by the kiosk', function () {
    expect(fn () => app(OpenKioskCheckoutAction::class)->handle($this->unit, $this->package, PaymentMethod::Cash))
        ->toThrow(InvalidArgumentException::class);
});

test('a package belonging to another unit type is refused', function () {
    $lain = Package::factory()->create(['price' => 10_000]);

    expect(fn () => app(OpenKioskCheckoutAction::class)->handle($this->unit, $lain, PaymentMethod::Qris))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * Settle dipanggil penjadwal tiap menit. Sesi yang sudah berjalan tidak boleh
 * "dimulai" lagi — waktunya akan mundur dan pelanggan mendapat lebih banyak
 * dari yang dibayar.
 */
test('settling twice never restarts a session that is already running', function () {
    ['payment' => $payment] = app(OpenKioskCheckoutAction::class)
        ->handle($this->unit, $this->package, PaymentMethod::Qris, 'Budi');

    Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'transaction_status' => 'settlement',
        'gross_amount' => '25000.00',
    ])]);

    app(SettleQrisPaymentAction::class)->handle($payment);
    $mulaiPertama = $payment->rentalSession->fresh()->started_at;

    app(SettleQrisPaymentAction::class)->handle($payment->fresh());

    expect($payment->rentalSession->fresh()->started_at->equalTo($mulaiPertama))->toBeTrue();
});
