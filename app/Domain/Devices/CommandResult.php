<?php

namespace App\Domain\Devices;

final class CommandResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly string $message,
    ) {}

    public static function success(string $message = 'OK'): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
