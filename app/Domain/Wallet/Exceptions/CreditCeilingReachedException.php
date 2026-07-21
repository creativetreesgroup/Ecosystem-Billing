<?php

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * Saldo sudah menyentuh batas minus yang diizinkan. Dibedakan dari
 * InsufficientBalanceException: yang itu "tidak boleh minus sama sekali", yang
 * ini "sudah minus sejauh yang diizinkan, wajib lunasi dulu".
 */
class CreditCeilingReachedException extends RuntimeException {}
