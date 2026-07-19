<?php

namespace App\Domain\Devices;

enum DeviceAlertStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
}
