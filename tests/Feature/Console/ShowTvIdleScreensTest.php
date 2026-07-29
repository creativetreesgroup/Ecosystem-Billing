<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Saat outlet buka, semua TV nganggur menampilkan QR — tapi unit yang sedang
 * dipakai TIDAK diganggu (menimpanya menutupi game pelanggan yang membayar).
 */
test('it casts the QR to idle units but skips units in use', function () {
    config(['services.home_assistant.base_url' => 'http://ha.test', 'services.home_assistant.token' => 'secret']);
    Http::fake(['ha.test/*' => Http::response([], 200)]);

    $idle = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'control_ref' => 'media_player.idle', 'code' => 'PS-IDLE']);
    $busy = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'control_ref' => 'media_player.busy', 'code' => 'PS-BUSY']);

    app(StartSessionAction::class)->handle($busy, User::factory()->create(), SessionType::Open);
    Http::fake(['ha.test/*' => Http::response([], 200)]); // reset rekaman setelah start

    $this->artisan('tv:show-idle')->assertSuccessful();

    Http::assertSent(fn ($r) => $r['entity_id'] === 'media_player.idle');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'play_media') && ($r['entity_id'] ?? null) === 'media_player.busy');
});
