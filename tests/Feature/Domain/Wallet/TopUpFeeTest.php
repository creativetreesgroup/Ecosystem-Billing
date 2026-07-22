<?php

use App\Domain\Billing\Actions\SettleQrisPaymentAction;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\IntegrationKey;
use App\Domain\Settings\SettingKey;
use App\Domain\Wallet\Actions\OpenTopUpAction;
use App\Domain\Wallet\TopUpFee;
use App\Domain\Wallet\WalletTransactionType;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Biaya admin isi saldo: DITAMBAHKAN di atas nominal. Pelanggan membayar
 * nominal + biaya; yang MASUK ke saldonya tetap persis nominal pilihannya.
 * Biaya bukan saldo — ia tak pernah masuk dompet. Diuji ketat karena ini uang.
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
    $this->customer = Customer::factory()->create();

    Setting::put(SettingKey::TopUpAdminFee, 2_500);

    Http::fake([
        'api.sandbox.midtrans.com/v2/charge' => Http::response([
            'actions' => [['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/qr/abc']],
        ]),
    ]);
});

test('the fee is added on top: customer pays amount+fee, balance gets exactly the chosen amount', function () {
    ['payment' => $payment] = app(OpenTopUpAction::class)->handle($this->customer, 50_000, PaymentMethod::Qris);

    // amount = total dibayar (dipakai gateway); fee terpisah; masuk = amount - fee.
    expect($payment->amount)->toBe(52_500)
        ->and($payment->fee)->toBe(2_500)
        ->and($payment->creditedAmount())->toBe(50_000);

    // Gateway menagih & melaporkan TOTAL (52.500) — penjaga kecocokan cocok.
    Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response([
        'transaction_status' => 'settlement',
        'gross_amount' => '52500.00',
    ])]);

    app(SettleQrisPaymentAction::class)->handle($payment);

    // Saldo yang masuk = 50.000 (bukan 52.500). Biaya tidak pernah jadi saldo.
    expect($this->customer->fresh()->balance)->toBe(50_000)
        ->and($this->customer->walletTransactions()->sole()->type)->toBe(WalletTransactionType::TopUp)
        ->and($this->customer->walletTransactions()->sole()->amount)->toBe(50_000);
});

test('transfer top-up also carries the fee', function () {
    ['payment' => $payment] = app(OpenTopUpAction::class)->handle($this->customer, 100_000, PaymentMethod::Transfer);

    expect($payment->amount)->toBe(102_500)
        ->and($payment->fee)->toBe(2_500)
        ->and($payment->creditedAmount())->toBe(100_000);
});

test('cash is exempt, QRIS and transfer carry the flat fee', function () {
    expect(TopUpFee::for(PaymentMethod::Cash))->toBe(0)
        ->and(TopUpFee::for(PaymentMethod::Qris))->toBe(2_500)
        ->and(TopUpFee::for(PaymentMethod::Transfer))->toBe(2_500);
});

test('fee of zero behaves exactly like no fee', function () {
    Setting::put(SettingKey::TopUpAdminFee, 0);

    ['payment' => $payment] = app(OpenTopUpAction::class)->handle($this->customer, 50_000, PaymentMethod::Qris);

    expect($payment->amount)->toBe(50_000)
        ->and($payment->fee)->toBe(0)
        ->and($payment->creditedAmount())->toBe(50_000);
});

test('the top-up limit is checked on the chosen amount, not the amount plus fee', function () {
    // MAXIMUM tepat masih boleh meski total+biaya melebihi MAXIMUM.
    ['payment' => $payment] = app(OpenTopUpAction::class)->handle($this->customer, OpenTopUpAction::MAXIMUM, PaymentMethod::Qris);

    expect($payment->fee)->toBe(2_500)
        ->and($payment->amount)->toBe(OpenTopUpAction::MAXIMUM + 2_500)
        ->and($payment->creditedAmount())->toBe(OpenTopUpAction::MAXIMUM);
});
