<?php

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
use App\Domain\Wallet\Actions\OpenTopUpAction;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\WalletTransactionType;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Integration::query()->where('key', IntegrationKey::Midtrans)->delete();
    Integration::factory()->create([
        'key' => IntegrationKey::Midtrans,
        'base_url' => 'https://api.sandbox.midtrans.com',
        'token' => 'SB-Mid-server-uji',
        'is_active' => true,
    ]);

    User::factory()->owner()->create();
    $this->customer = Customer::factory()->create();
    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $this->package = Package::factory()->for($this->unit->unitType)->create(['price' => 25_000, 'duration_minutes' => 60]);

    Http::fake([
        'api.sandbox.midtrans.com/v2/charge' => Http::response([
            'actions' => [['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/qr/abc']],
        ]),
    ]);
});

test('a customer with enough balance plays straight away', function () {
    $this->customer->update(['balance' => 40_000]);

    $session = app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $this->package);

    expect($session->status)->toBe(SessionStatus::Active)
        ->and($session->customer_id)->toBe($this->customer->id)
        ->and($session->ends_at)->not->toBeNull()
        ->and($this->customer->fresh()->balance)->toBe(15_000);

    $ledger = $this->customer->walletTransactions()->latest('id')->first();

    expect($ledger->type)->toBe(WalletTransactionType::Spend)
        ->and($ledger->amount)->toBe(-25_000)
        ->and($ledger->rental_session_id)->toBe($session->id);
});

/**
 * Potongan saldo dan pembuatan sesi berada di SATU transaksi. Kalau terpisah,
 * kegagalan di antaranya meninggalkan salah satu dari dua keadaan yang sama
 * buruknya: pelanggan bermain tanpa dipotong, atau saldonya terpotong tanpa
 * pernah bermain.
 */
test('a unit taken at the last moment leaves the balance untouched', function () {
    $this->customer->update(['balance' => 40_000]);
    app(StartSessionAction::class)->handle($this->unit, User::factory()->create(), SessionType::Open);

    expect(fn () => app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $this->package))
        ->toThrow(UnitAlreadyActiveException::class);

    expect($this->customer->fresh()->balance)->toBe(40_000)
        ->and($this->customer->walletTransactions()->count())->toBe(0);
});

test('a customer short of balance is told so, and nothing moves', function () {
    $this->customer->update(['balance' => 10_000]);

    expect(fn () => app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $this->package))
        ->toThrow(InsufficientBalanceException::class);

    expect($this->customer->fresh()->balance)->toBe(10_000)
        ->and($this->unit->fresh()->activeSession)->toBeNull();
});

test('a QRIS top-up only credits the wallet once the gateway confirms it', function () {
    ['payment' => $payment] = app(OpenTopUpAction::class)
        ->handle($this->customer, 50_000, PaymentMethod::Qris);

    expect($this->customer->fresh()->balance)->toBe(0);

    Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'transaction_status' => 'settlement',
        'gross_amount' => '50000.00',
    ])]);

    app(SettleQrisPaymentAction::class)->handle($payment);

    expect($this->customer->fresh()->balance)->toBe(50_000)
        ->and($this->customer->walletTransactions()->sole()->type)->toBe(WalletTransactionType::TopUp);
});

/**
 * Penjadwal menanyakan status tiap menit. Tanpa penjaga ganda-kredit, saldo
 * pelanggan bertambah lagi setiap putaran — uang yang tidak pernah ada.
 */
test('polling repeatedly never credits the same top-up twice', function () {
    ['payment' => $payment] = app(OpenTopUpAction::class)
        ->handle($this->customer, 50_000, PaymentMethod::Qris);

    Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'transaction_status' => 'settlement',
        'gross_amount' => '50000.00',
    ])]);

    app(SettleQrisPaymentAction::class)->handle($payment);
    app(SettleQrisPaymentAction::class)->handle($payment->fresh());
    app(SettleQrisPaymentAction::class)->handle($payment->fresh());

    expect($this->customer->fresh()->balance)->toBe(50_000)
        ->and($this->customer->walletTransactions()->count())->toBe(1);
});

test('a transfer top-up credits the wallet when the cashier accepts the proof', function () {
    ['payment' => $payment] = app(OpenTopUpAction::class)
        ->handle($this->customer, 75_000, PaymentMethod::Transfer);

    $payment->update(['status' => PaymentStatus::AwaitingVerification, 'proof_path' => 'payment-proofs/uji.jpg']);

    expect($this->customer->fresh()->balance)->toBe(0);

    app(VerifyPaymentAction::class)->handle($payment->fresh(), User::factory()->create());

    expect($this->customer->fresh()->balance)->toBe(75_000);
});

/**
 * Membiarkan beberapa QR isi saldo hidup sekaligus berarti pelanggan bisa
 * membayar dua-duanya sambil mengira saldonya bertambah sekali.
 */
test('a new top-up cancels the previous unpaid one', function () {
    ['payment' => $pertama] = app(OpenTopUpAction::class)->handle($this->customer, 50_000, PaymentMethod::Qris);
    ['payment' => $kedua] = app(OpenTopUpAction::class)->handle($this->customer, 100_000, PaymentMethod::Qris);

    expect($pertama->fresh()->status)->toBe(PaymentStatus::Expired)
        ->and($kedua->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('top-up amounts outside the allowed range are refused', function (int $amount) {
    expect(fn () => app(OpenTopUpAction::class)->handle($this->customer, $amount, PaymentMethod::Qris))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'terlalu kecil' => [5_000],
    'salah ketik nol' => [20_000_000],
]);

test('cash top-up is left to the cashier', function () {
    expect(fn () => app(OpenTopUpAction::class)->handle($this->customer, 50_000, PaymentMethod::Cash))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * Alur penuh yang diminta: saldo kosong → isi → main, tanpa kasir sama sekali.
 */
test('empty wallet, top up, then play — end to end', function () {
    ['payment' => $payment] = app(OpenTopUpAction::class)
        ->handle($this->customer, 50_000, PaymentMethod::Qris);

    Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'transaction_status' => 'settlement',
        'gross_amount' => '50000.00',
    ])]);
    app(SettleQrisPaymentAction::class)->handle($payment);

    $session = app(PlayFromWalletAction::class)->handle($this->customer->fresh(), $this->unit, $this->package);

    $fresh = $this->customer->fresh();

    expect($session->status)->toBe(SessionStatus::Active)
        ->and($fresh->balance)->toBe(25_000)
        ->and($fresh->balance)->toBe($fresh->ledgerBalance());
});
