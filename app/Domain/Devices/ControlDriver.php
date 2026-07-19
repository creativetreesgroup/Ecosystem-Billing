<?php

namespace App\Domain\Devices;

enum ControlDriver: string
{
    case HomeAssistant = 'home_assistant';
    case Tasmota = 'tasmota';
    case Manual = 'manual';
}
