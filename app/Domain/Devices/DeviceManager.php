<?php

namespace App\Domain\Devices;

use App\Domain\Devices\Drivers\HomeAssistantDriver;
use App\Domain\Devices\Drivers\ManualDriver;
use App\Domain\Devices\Drivers\TasmotaDriver;
use App\Domain\Devices\Events\UnitPowerStateChanged;
use App\Domain\Devices\Jobs\VerifyUnitPoweredOffJob;
use App\Models\DeviceAlert;
use App\Models\Integration;
use App\Models\Unit;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeviceManager
{
    public function __construct(private readonly Container $container) {}

    public function driverFor(Unit $unit): TvControl
    {
        return match ($unit->control_driver) {
            ControlDriver::Manual => $this->container->make(ManualDriver::class),
            ControlDriver::HomeAssistant => $this->homeAssistant(),
            ControlDriver::Tasmota => $this->tasmota(),
        };
    }

    /**
     * Dipakai driverFor() dan juga discovery TV — discovery tidak terikat ke
     * satu Unit (justru dipakai saat unit-nya belum punya control_ref).
     *
     * Kredensial dibaca dari DATABASE dulu, .env sebagai cadangan. Urutannya
     * bukan selera: outlet yang sudah berjalan tidak boleh kehilangan koneksi
     * hanya karena barisnya belum diisi, dan pemilik yang mengisi lewat panel
     * harus menang atas nilai .env lama yang mungkin sudah kedaluwarsa.
     */
    public function homeAssistant(): HomeAssistantDriver
    {
        $integration = Integration::for(IntegrationKey::HomeAssistant);

        return new HomeAssistantDriver(
            baseUrl: $integration?->is_active && filled($integration->base_url)
                ? $integration->base_url
                : (string) config('services.home_assistant.base_url'),
            token: $integration?->is_active && filled($integration->token)
                ? $integration->token
                : (string) config('services.home_assistant.token'),
        );
    }

    public function tasmota(): TasmotaDriver
    {
        return new TasmotaDriver(
            host: config('services.mqtt.host'),
            port: config('services.mqtt.port'),
            username: config('services.mqtt.username'),
            password: config('services.mqtt.password'),
        );
    }

    /**
     * State billing tidak pernah bergantung pada state device (prinsip arsitektur #1).
     * Perintah device dibungkus di sini supaya kegagalannya tidak pernah menggagalkan
     * transaksi billing yang memanggilnya — hanya dicatat sebagai log terstruktur.
     */
    public function attempt(Unit $unit, Closure $callback): ?CommandResult
    {
        try {
            return $callback($this->driverFor($unit));
        } catch (Throwable $e) {
            Log::warning('Perintah device gagal, sesi tetap lanjut.', [
                'unit_id' => $unit->id,
                'control_driver' => $unit->control_driver->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Perintah power-off tidak pernah bisa dipercaya sepenuhnya (TV tidak
     * merespons, jaringan putus, dll) — jadwalkan verifikasi belakangan
     * lewat VerifyUnitPoweredOffJob alih-alih memblokir alur billing.
     */
    public function powerOff(Unit $unit): ?CommandResult
    {
        $result = $this->attempt($unit, fn (TvControl $driver) => $driver->powerOff($unit));

        VerifyUnitPoweredOffJob::dispatch($unit->id)->delay(now()->addSeconds(10));

        return $result;
    }

    /**
     * Titik masuk tunggal untuk poller (`units:poll-state`) dan bridge MQTT
     * melaporkan power state yang baru mereka amati. Menyatukan di sini
     * supaya "state berubah → broadcast, dan kalau jadi unreachable → device_alert"
     * konsisten dari kedua sumber, bukan diduplikasi di masing-masing caller.
     */
    public function reportState(Unit $unit, PowerState $state): void
    {
        if ($unit->power_state === $state) {
            $unit->update(['last_seen_at' => now()]);

            return;
        }

        $unit->update(['power_state' => $state, 'last_seen_at' => now()]);
        UnitPowerStateChanged::dispatch($unit->id);

        if ($state === PowerState::Unreachable) {
            DeviceAlert::raiseOnce($unit->id, DeviceAlertType::DeviceOffline, "Unit {$unit->code} tidak bisa dihubungi.");
        }
    }
}
