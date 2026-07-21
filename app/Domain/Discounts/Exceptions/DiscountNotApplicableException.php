<?php

namespace App\Domain\Discounts\Exceptions;

use RuntimeException;

/**
 * Voucher/promo tidak bisa dipakai untuk transaksi ini — kode salah, kedaluwarsa,
 * kuota habis, atau syarat minimal tak terpenuhi. Pesannya sudah ramah-pelanggan
 * supaya bisa langsung ditampilkan di kios.
 */
class DiscountNotApplicableException extends RuntimeException {}
