<?php

namespace App\Domain\Devices;

use App\Domain\Devices\Drivers\ManualDriver;
use App\Models\Unit;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DeviceManager
{
    public function __construct(private readonly Container $container) {}

    public function driverFor(Unit $unit): TvControl
    {
        return match ($unit->control_driver) {
            ControlDriver::Manual => $this->container->make(ManualDriver::class),
            ControlDriver::HomeAssistant, ControlDriver::Tasmota => throw new RuntimeException(
                "Driver {$unit->control_driver->value} belum diimplementasikan — dikerjakan di Fase 5."
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
}
