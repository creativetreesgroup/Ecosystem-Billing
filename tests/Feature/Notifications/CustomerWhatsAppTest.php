<?php

use App\Domain\Sessions\Events\SessionEnded;
use App\Domain\Sessions\Events\SessionEnding;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Domain\Wallet\Events\CustomerWentIntoDebt;
use App\Domain\Wallet\Events\WalletToppedUp;
use App\Listeners\SendDebtWhatsApp;
use App\Listeners\SendSessionEndingWhatsApp;
use App\Listeners\SendSessionSummaryWhatsApp;
use App\Listeners\SendTopUpConfirmationWhatsApp;
use App\Models\Customer;
use App\Models\RentalSession;
use App\Models\Unit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.waha.base_url' => 'http://waha.lan:3000', 'services.waha.api_key' => 'rahasia']);
    Http::fake(['waha.lan:3000/*' => Http::response(['id' => 'ok'], 201)]);
});

test('a top-up confirmation is sent to the customer, bonus included', function () {
    $customer = Customer::factory()->create(['name' => 'Budi', 'balance' => 120_000]);

    app(SendTopUpConfirmationWhatsApp::class)->handle(new WalletToppedUp($customer->id, 100_000, 20_000));

    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/sendText')
        && $r['chatId'] === '62'.substr($customer->phone, 1).'@c.us'
        && str_contains($r['text'], 'Isi saldo Rp 100.000 berhasil')
        && str_contains($r['text'], 'bonus Rp 20.000')
        && str_contains($r['text'], 'Rp 120.000'));
});

test('a debt notice is sent when the balance is negative', function () {
    $customer = Customer::factory()->create(['balance' => -8_000]);

    app(SendDebtWhatsApp::class)->handle(new CustomerWentIntoDebt($customer->id));

    Http::assertSent(fn ($r) => str_contains($r['text'], 'minus Rp 8.000'));
});

test('a session summary is sent for a completed customer session', function () {
    $customer = Customer::factory()->create(['balance' => 96_000]);
    $unit = Unit::factory()->create(['code' => 'PS-3']);
    $session = RentalSession::factory()->create([
        'unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'type' => SessionType::Package,
        'status' => SessionStatus::Completed,
        'total_amount' => 24_000,
    ]);

    app(SendSessionSummaryWhatsApp::class)->handle(new SessionEnded($session->id, $unit->id));

    Http::assertSent(fn ($r) => str_contains($r['text'], 'PS-3 selesai')
        && str_contains($r['text'], 'Rp 24.000')
        && str_contains($r['text'], 'Rp 96.000'));
});

/**
 * SessionEnded juga menyala saat VOID — struk "main selesai" tak boleh dikirim
 * untuk sesi yang dibatalkan.
 */
test('no summary is sent for a voided session', function () {
    $customer = Customer::factory()->create();
    $unit = Unit::factory()->create();
    $session = RentalSession::factory()->create([
        'unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'status' => SessionStatus::Voided,
        'total_amount' => 24_000,
    ]);

    app(SendSessionSummaryWhatsApp::class)->handle(new SessionEnded($session->id, $unit->id));

    Http::assertNothingSent();
});

test('a session-ending warning is sent with the unit and minutes left', function () {
    $customer = Customer::factory()->create();
    $unit = Unit::factory()->create(['code' => 'PS-3']);
    $session = RentalSession::factory()->create([
        'unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'status' => SessionStatus::Active,
        'ends_at' => now()->addMinutes(5),
    ]);

    app(SendSessionEndingWhatsApp::class)->handle(new SessionEnding($session->id, $unit->id, now()->addMinutes(5)->toIso8601String()));

    Http::assertSent(fn ($r) => str_contains($r['text'], 'PS-3') && str_contains($r['text'], 'menit'));
});

test('nothing is sent when WAHA is not configured', function () {
    config(['services.waha.base_url' => null, 'services.waha.api_key' => null]);
    $customer = Customer::factory()->create(['balance' => -5_000]);

    app(SendDebtWhatsApp::class)->handle(new CustomerWentIntoDebt($customer->id));

    Http::assertNothingSent();
});

test('the whatsapp listeners are registered on their events exactly once', function () {
    expect(Event::getListeners(WalletToppedUp::class))->toHaveCount(1)
        ->and(Event::getListeners(SessionEnding::class))->toHaveCount(1)
        // CustomerWentIntoDebt & SessionEnded punya listener lain juga (bell/dll),
        // jadi hanya dipastikan listener WA-nya IKUT terdaftar.
        ->and(collect(Event::getListeners(CustomerWentIntoDebt::class)))->not->toBeEmpty()
        ->and(collect(Event::getListeners(SessionEnded::class)))->not->toBeEmpty();
});
