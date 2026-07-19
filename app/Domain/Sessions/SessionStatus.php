<?php

namespace App\Domain\Sessions;

enum SessionStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Voided = 'voided';
}
