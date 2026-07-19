<?php

namespace App\Domain\Devices;

enum PowerState: string
{
    case On = 'on';
    case Standby = 'standby';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';
}
