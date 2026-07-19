<?php

namespace App\Domain\Devices;

use App\Domain\Devices\Drivers\HomeAssistantDriver;
use App\Domain\Devices\Drivers\ManualDriver;
use App\Domain\Devices\Drivers\TasmotaDriver;
use App\Domain\Devices\Jobs\VerifyUnitPoweredOffJob;
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
            ControlDriver::HomeAssistant => new HomeAssistantDriver(
                baseUrl: config('services.home_assistant.base_url'),
                token: config('services.home_assistant.token'),
            ),
            ControlDriver::Tasmota => new TasmotaDriver(
                host: config('services.mqtt.host'),
                port: config('services.mqtt.port'),
                username: config('services.mqtt.username'),
                password: config('services.mqtt.password'),
            ),
        };
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
}
