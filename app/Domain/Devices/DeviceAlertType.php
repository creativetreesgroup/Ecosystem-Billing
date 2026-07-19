<?php

namespace App\Domain\Devices;

enum DeviceAlertType: string
{
    case PowerOffFailed = 'power_off_failed';
    case DeviceOffline = 'device_offline';
    case StateMismatch = 'state_mismatch';
}
