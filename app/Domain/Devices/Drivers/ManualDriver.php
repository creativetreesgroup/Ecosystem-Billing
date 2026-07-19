<?php

namespace App\Domain\Devices\Drivers;

use App\Domain\Devices\Capability;
use App\Domain\Devices\CommandResult;
use App\Domain\Devices\DeviceAlertType;
use App\Domain\Devices\PowerState;
use App\Domain\Devices\TvControl;
use App\Models\DeviceAlert;
use App\Models\Unit;
use Illuminate\Support\Facades\Log;

/**
 * Unit tanpa kontrol jaringan. Setiap perintah adalah no-op — operator
 * menekan tombol TV secara fisik. powerOff() tetap membuat device_alert
 * supaya kasir tahu harus mematikan manual, karena driver ini tidak
 * pernah bisa memverifikasi state TV yang sebenarnya.
 */
class ManualDriver implements TvControl
{
    public function powerOn(Unit $unit): CommandResult
    {
        return CommandResult::success('Manual: nyalakan TV secara fisik.');
    }

    public function powerOff(Unit $unit): CommandResult
    {
        DeviceAlert::raiseOnce(
            $unit->id,
            DeviceAlertType::PowerOffFailed,
            'Matikan TV secara manual — unit ini tidak punya kontrol jaringan.',
        );

        return CommandResult::success('Manual: matikan TV secara fisik.');
    }

    public function state(Unit $unit): PowerState
    {
        return PowerState::Unknown;
    }

    public function supports(Unit $unit, Capability $capability): bool
    {
        return false;
    }

    public function notify(Unit $unit, string $message): CommandResult
    {
        Log::info('Notifikasi dilewati: unit manual tidak mendukung notify.', ['unit_id' => $unit->id]);

        return CommandResult::success('Manual: notifikasi tidak didukung, dilewati.');
    }
}
