<?php

namespace App\Console\Commands;

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\Events\UnitPowerStateChanged;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fallback poll untuk unit ber-driver home_assistant: Tasmota sudah dapat
 * push realtime lewat MQTT LWT/POWER (lihat bridge:mqtt-listen), tapi HA
 * tidak mendorong perubahan state ke aplikasi ini, jadi harus ditanya
 * berkala. Hanya menulis DB & broadcast kalau state benar-benar berubah,
 * supaya tidak membanjiri dashboard dengan event kosong tiap siklus.
 */
class PollUnitPowerState extends Command
{
    protected $signature = 'units:poll-state';

    protected $description = 'Polling state TV lewat Home Assistant untuk unit ber-driver home_assistant.';

    public function handle(DeviceManager $devices): int
    {
        $units = Unit::query()
            ->where('control_driver', ControlDriver::HomeAssistant)
            ->where('is_active', true)
            ->get();

        $changed = 0;

        foreach ($units as $unit) {
            try {
                $state = $devices->driverFor($unit)->state($unit);
            } catch (Throwable $e) {
                Log::warning('Polling state gagal: driver tidak bisa dihubungi.', [
                    'unit_id' => $unit->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($state === $unit->power_state) {
                continue;
            }

            $unit->update(['power_state' => $state, 'last_seen_at' => now()]);
            UnitPowerStateChanged::dispatch($unit->id);
            $changed++;
        }

        $this->info("Polling selesai: {$units->count()} unit diperiksa, {$changed} berubah.");

        return self::SUCCESS;
    }
}
