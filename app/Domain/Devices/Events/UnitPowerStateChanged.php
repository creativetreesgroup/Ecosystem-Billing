<?php

namespace App\Domain\Devices\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UnitPowerStateChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $unitId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('panel.units')];
    }

    public function broadcastAs(): string
    {
        return 'unit.power-state-changed';
    }
}
