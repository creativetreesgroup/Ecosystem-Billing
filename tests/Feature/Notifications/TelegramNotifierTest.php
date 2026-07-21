<?php

use App\Models\User;
use App\Notifications\BellNotifier;
use App\Notifications\TelegramNotifier;
use Filament\Notifications\Notification;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;

/**
 * Notifikasi operasional juga dikirim ke chat Telegram owner/staf, sebagai teks
 * biasa (markup body dibersihkan supaya nama/nominal tak merusak pesan).
 */
test('it posts to the Telegram bot API when configured', function () {
    config(['services.telegram.bot_token' => 'BOTTOKEN', 'services.telegram.chat_id' => '999']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    TelegramNotifier::send('Alert perangkat', 'TV <b>PS-07</b> tidak menjawab');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/botBOTTOKEN/sendMessage')
        && (string) $request['chat_id'] === '999'
        && str_contains($request['text'], 'Alert perangkat')
        && str_contains($request['text'], 'TV PS-07 tidak menjawab')
        && ! str_contains($request['text'], '<b>'));
});

/**
 * Telegram itu TAMBAHAN di atas lonceng, bukan pengganti — tanpa konfigurasi ia
 * diam, tak menggagalkan apa pun.
 */
test('it is silent when not configured', function () {
    config(['services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);
    Http::fake();

    TelegramNotifier::send('x', 'y');

    Http::assertNothingSent();
});

/**
 * Satu notifikasi lonceng → SATU pesan Telegram ke chat ops (bukan per-user).
 * Leg Telegram-nya DITUNDA pasca-respons supaya tak menahan permintaan pelanggan;
 * terminate() menjalankan callback tertunda itu di dalam tes.
 */
test('a bell notification reaches Telegram exactly once, after response', function () {
    config(['services.telegram.bot_token' => 'BOT', 'services.telegram.chat_id' => '1']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    User::factory()->create(['is_active' => true]);
    User::factory()->create(['is_active' => true]);

    BellNotifier::send(Notification::make()->title('Alert')->body('TV mati'));

    // Belum terkirim SEBELUM respons (ditunda) — inilah yang tak menahan pelanggan.
    Http::assertNothingSent();

    // Jalankan callback tertunda (di produksi: middleware pasca-respons).
    app(DeferredCallbackCollection::class)->invoke();

    Http::assertSentCount(1);
});
