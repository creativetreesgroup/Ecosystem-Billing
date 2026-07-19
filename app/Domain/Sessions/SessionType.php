<?php

namespace App\Domain\Sessions;

enum SessionType: string
{
    case Open = 'open';
    case Package = 'package';
}
