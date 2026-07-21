<?php

use App\Domain\Customers\Otp\LoggingOtpChannel;
use App\Domain\Customers\Otp\OtpChannel;
use App\Domain\Customers\Otp\WahaOtpChannel;
use Illuminate\Support\Facades\Http;

/**
 * OTP dikirim lewat WAHA: nomor lokal 08xxx diubah ke chatId internasional
 * <62...>@c.us, API key di header, kodenya masuk badan pesan.
 */
test('it sends the OTP to WAHA with the right chat id, api key, and code', function () {
    config([
        'services.waha.base_url' => 'http://waha.lan:3000',
        'services.waha.api_key' => 'rahasia',
        'services.waha.session' => 'default',
    ]);
    Http::fake(['waha.lan:3000/*' => Http::response(['id' => 'ok'], 201)]);

    expect(app(WahaOtpChannel::class)->send('08123456789', '123456'))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/sendText')
        && $request->hasHeader('X-Api-Key', 'rahasia')
        && $request['chatId'] === '628123456789@c.us'
        && $request['session'] === 'default'
        && str_contains($request['text'], '123456'));
});

/**
 * Gagal kirim adalah keadaan normal — kembalikan false (alur menawarkan PIN),
 * BUKAN melempar.
 */
test('it returns false when WAHA responds with an error', function () {
    config(['services.waha.base_url' => 'http://waha.lan:3000', 'services.waha.api_key' => 'rahasia']);
    Http::fake(['waha.lan:3000/*' => Http::response([], 500)]);

    expect(app(WahaOtpChannel::class)->send('08123456789', '123456'))->toBeFalse();
});

test('isConfigured is true only when base url and api key are set', function () {
    config(['services.waha.base_url' => null, 'services.waha.api_key' => null]);
    expect(app(WahaOtpChannel::class)->isConfigured())->toBeFalse();

    config(['services.waha.base_url' => 'http://waha.lan:3000', 'services.waha.api_key' => 'rahasia']);
    expect(app(WahaOtpChannel::class)->isConfigured())->toBeTrue();
});

/**
 * Satu tempat memilih penyalur: WAHA bila dikonfigurasi, selain itu penyalur
 * log yang menolak produksi.
 */
test('the OTP channel binding picks WAHA when configured, otherwise the log channel', function () {
    config(['services.waha.base_url' => 'http://waha.lan:3000', 'services.waha.api_key' => 'rahasia']);
    expect(app(OtpChannel::class))->toBeInstanceOf(WahaOtpChannel::class);

    config(['services.waha.base_url' => null, 'services.waha.api_key' => null]);
    expect(app(OtpChannel::class))->toBeInstanceOf(LoggingOtpChannel::class);
});
