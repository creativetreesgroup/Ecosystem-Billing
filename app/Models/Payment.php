<?php

namespace App\Models;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu upaya pembayaran atas sebuah sesi.
 *
 * Sebelum ini "sudah bayar" hanyalah kolom payment_method yang terisi. Itu
 * cukup selama kasir memegang uangnya di depan — kasirnya sendiri yang jadi
 * bukti. Begitu pelanggan bisa membayar sendiri lewat QR, kolom terisi tidak
 * lagi berarti uang masuk: QRIS bisa dibatalkan di tengah jalan, dan bukti
 * transfer bisa palsu, salah nominal, atau milik transaksi lain.
 */
#[Fillable([
    'rental_session_id', 'customer_id', 'method', 'status', 'amount', 'reference',
    'proof_path', 'verified_by', 'verified_at', 'rejection_reason',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function rentalSession(): BelongsTo
    {
        return $this->belongsTo(RentalSession::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * Menunggu kasir memeriksa bukti transfer. Dipisah dari status mentahnya
     * supaya lencana "butuh perhatian" di panel punya satu definisi.
     */
    public function needsCashierAttention(): bool
    {
        return $this->status === PaymentStatus::AwaitingVerification;
    }
}
