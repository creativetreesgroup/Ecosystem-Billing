<?php

namespace App\Domain\Customers\Exceptions;

use RuntimeException;

class TooManyPinAttemptsException extends RuntimeException
{
    public function __construct(public readonly int $secondsRemaining)
    {
        parent::__construct("Terlalu banyak percobaan. Coba lagi dalam {$secondsRemaining} detik.");
    }
}
