<?php

use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\IntegrationKey;
use App\Domain\Devices\NetworkScanner;
use App\Filament\Resources\Integrations\Pages\EditIntegration;
use App\Models\Integration;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * §14 semula mensyaratkan HA_TOKEN hanya lewat .env. Pelonggarannya tercatat
 * di DECISIONS.md, TAPI syarat-syaratnya harus tetap ditegakkan mesin, bukan
 * niat baik. Berkas ini yang menegakkannya.
 */
test('the token is encrypted at rest, never readable from a database dump', function () {
    $integration = Integration::factory()->create(['token' => 'rahasia-sungguhan-123']);

    $stored = DB::table('integrations')->where('id', $integration->id)->value('token');

    expect($stored)->not->toBe('rahasia-sungguhan-123')
        ->and($stored)->not->toContain('rahasia-sungguhan')
        ->and($integration->fresh()->token)->toBe('rahasia-sungguhan-123');
});

test('the token is never sent back to the browser', function () {
    $integration = Integration::factory()->create(['token' => 'rahasia-sungguhan-123']);

    Livewire::actingAs(User::factory()->owner()->create())
        ->test(EditIntegration::class, ['record' => $integration->getRouteKey()])
        ->assertDontSee('rahasia-sungguhan-123')
        ->assertSchemaStateSet(['token' => null]);
});

/**
 * Tanpa ini, menyimpan perubahan kecil (mis. hanya membetulkan alamat) akan
 * MENGHAPUS tokennya diam-diam dan memutus seluruh kendali TV outlet.
 */
test('saving with an empty token box keeps the token that was already there', function () {
    $integration = Integration::factory()->create(['token' => 'token-lama']);

    Livewire::actingAs(User::factory()->owner()->create())
        ->test(EditIntegration::class, ['record' => $integration->getRouteKey()])
        ->fillForm(['base_url' => 'http://192.168.1.50:8123', 'token' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($integration->fresh())
        ->token->toBe('token-lama')
        ->base_url->toBe('http://192.168.1.50:8123');
});

test('a kasir cannot reach the integration screen at all', function () {
    $integration = Integration::factory()->create();

    expect(User::factory()->create()->can('view', $integration))->toBeFalse()
        ->and(User::factory()->create()->can('update', $integration))->toBeFalse()
        ->and(User::factory()->owner()->create()->can('update', $integration))->toBeTrue();
});

/**
 * Urutannya bukan selera: outlet yang sudah berjalan tidak boleh kehilangan
 * koneksi hanya karena barisnya belum diisi, dan pemilik yang mengisi lewat
 * panel harus menang atas nilai .env lama yang mungkin sudah kedaluwarsa.
 */
test('the database wins over .env, but .env still covers an empty row', function () {
    config()->set('services.home_assistant.base_url', 'http://dari-env:8123');
    config()->set('services.home_assistant.token', 'token-dari-env');

    Integration::factory()->withoutToken()->create([
        'key' => IntegrationKey::HomeAssistant,
        'base_url' => null,
    ]);

    expect(app(DeviceManager::class)->homeAssistant()->isConfigured())->toBeTrue();

    Integration::for(IntegrationKey::HomeAssistant)->update([
        'base_url' => 'http://dari-panel:8123',
        'token' => 'token-dari-panel',
    ]);

    // Dimatikan berarti kembali ke .env, bukan berhenti mencoba.
    Integration::for(IntegrationKey::HomeAssistant)->update(['is_active' => false]);

    expect(app(DeviceManager::class)->homeAssistant()->isConfigured())->toBeTrue();
});

/**
 * Alamat HA adalah SATU-SATUNYA dari tiga langkah pemasangan yang bisa
 * diotomatiskan. Token harus dibuat manusia di dalam HA, dan kode pairing 6
 * digit harus dibaca dari layar TV — keduanya titik keamanan, bukan kekurangan
 * yang bisa ditambal.
 */
test('the address can be found on the network and filled in', function () {
    $this->mock(NetworkScanner::class)
        ->shouldReceive('findHomeAssistant')
        ->andReturn(['http://192.168.100.10:8123']);

    $integration = Integration::factory()->create(['base_url' => null]);
    $action = TestAction::make('findHomeAssistant')->schemaComponent('base_url');

    Livewire::actingAs(User::factory()->owner()->create())
        ->test(EditIntegration::class, ['record' => $integration->getRouteKey()])
        ->mountAction($action)
        ->setActionData(['base_url' => 'http://192.168.100.10:8123'])
        ->callMountedAction()
        ->assertSchemaStateSet(['base_url' => 'http://192.168.100.10:8123']);
});
