<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Otomasi TV: begitu sesi MULAI, layar QR dibersihkan supaya TV kembali ke game;
 * begitu sesi BERAKHIR, QR ditampilkan lagi untuk pelanggan berikutnya. Keduanya
 * dipanggil SINKRON di dalam aksi (seperti powerOn), jadi terjadi dalam hitungan
 * ratusan milidetik — bukan menunggu antrean.
 *
 * Yang diuji: perintah yang benar terkirim ke Home Assistant di saat yang tepat.
 * Apakah TV lalu benar-benar berpindah ke input PS5 bergantung perangkat (§14).
 */
beforeEach(function () {
    config(['services.home_assistant.base_url' => 'http://ha.test', 'services.home_assistant.token' => 'secret']);
    Http::fake(['ha.test/*' => Http::response([], 200)]);

    User::factory()->owner()->create();
    $this->unit = Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.tv_ps01',
    ]);
    $this->package = Package::factory()->for($this->unit->unitType)->create([
        'price' => 5_000,
        'duration_minutes' => 60,
        'is_active' => true,
    ]);
    $this->customer = Customer::factory()->withBalance(50_000)->create();
});

test('starting a session clears the QR from the TV so the game shows', function () {
    app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $this->package);

    Http::assertSent(fn (Request $r) => $r->url() === 'http://ha.test/api/services/media_player/media_stop'
        && $r['entity_id'] === 'media_player.tv_ps01');
});

test('ending a session shows the QR again for the next customer', function () {
    $session = app(PlayFromWalletAction::class)->handle($this->customer, $this->unit, $this->package);

    app(CompleteSessionAction::class)->handle($session);

    Http::assertSent(fn (Request $r) => $r->url() === 'http://ha.test/api/services/media_player/play_media'
        && $r['media_content_type'] === 'image/jpeg');
});
