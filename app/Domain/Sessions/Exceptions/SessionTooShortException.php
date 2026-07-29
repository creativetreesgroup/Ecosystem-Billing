<?php

namespace App\Domain\Sessions\Exceptions;

use RuntimeException;

/**
 * Sesi Open Play dihentikan sebelum menit pertama lewat. Membawa sisa detik
 * supaya layar bisa memberi tahu pelanggan berapa lama lagi harus menunggu,
 * bukan sekadar menolak.
 */
class SessionTooShortException extends RuntimeException
{
    public function __construct(public readonly int $secondsLeft)
    {
        parent::__construct("Tunggu {$secondsLeft} detik lagi sebelum berhenti.");
    }
}
