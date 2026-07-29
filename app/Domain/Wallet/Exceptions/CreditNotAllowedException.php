<?php

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * Akun ini belum boleh main dari kredit: saldonya nol/minus DAN belum pernah
 * mengisi saldo sama sekali. Tanpa aturan ini, siapa pun bisa mendaftar akun
 * baru (cukup nomor WA), main sampai batas minus, lalu meninggalkan akunnya —
 * dan kerugiannya ditanggung outlet tanpa penghalang.
 */
class CreditNotAllowedException extends RuntimeException {}
