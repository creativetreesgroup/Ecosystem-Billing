<?php

namespace App\Domain\Devices;

use App\Models\Unit;

interface TvControl
{
    public function powerOn(Unit $unit): CommandResult;

    public function powerOff(Unit $unit): CommandResult;

    public function state(Unit $unit): PowerState;

    public function supports(Unit $unit, Capability $capability): bool;

    public function notify(Unit $unit, string $message): CommandResult;
}
