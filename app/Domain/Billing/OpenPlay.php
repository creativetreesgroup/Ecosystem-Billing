<?php

namespace App\Domain\Billing;

/**
 * Aturan angka Open Play — main bebas, ditagih per menit dari saldo.
 *
 * Berbeda dari paket: paket dibayar di muka, Open Play mengalir. Saldo dipotong
 * saat berhenti; kalau habis, sisanya jadi UTANG (saldo minus) yang wajib
 * dilunasi. Dua batas menjaga outlet:
 */
final class OpenPlay
{
    /**
     * Sejauh mana saldo boleh minus sebelum pelanggan dipaksa berhenti &
     * melunasi. Ini paparan rugi maksimal outlet per akun — kalau pelanggan
     * pergi tanpa membayar, segini batas kerugiannya.
     *
     * ponytail: konstanta dulu; jadikan Setting saat owner perlu menyetelnya
     * per-outlet tanpa deploy.
     */
    public const CREDIT_CEILING = 50_000;

    /**
     * Menit pertama tetap ditagih penuh, dan sesi tidak boleh dihentikan
     * sebelum lewat. Tanpa ini, siapa pun bisa menyalakan TV lalu berhenti
     * seketika dan pergi tanpa membayar apa pun.
     */
    public const MIN_SECONDS = 60;
}
