<?php

namespace App\Models;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use Database\Factories\RentalSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'unit_id', 'opened_by', 'customer_name', 'type', 'package_id',
    'started_at', 'ends_at', 'ended_at', 'status', 'expiry_token',
    'base_amount', 'extra_amount', 'total_amount', 'payment_method',
    'paid_at', 'voided_by', 'void_reason', 'customer_id',
])]
class RentalSession extends Model
{
    /** @use HasFactory<RentalSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SessionType::class,
            'status' => SessionStatus::class,
            'payment_method' => PaymentMethod::class,
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'paid_at' => 'datetime',
            'base_amount' => 'integer',
            'extra_amount' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Pembayaran yang benar-benar lunas. Laporan pendapatan hanya boleh
     * menghitung ini — sesi yang selesai tapi buktinya belum diverifikasi
     * kasir BUKAN pemasukan, dan menghitungnya membuat laporan berbohong
     * tepat pada angka yang dipakai menutup kas.
     */
    public function settledPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->where('status', PaymentStatus::Paid);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(SessionExtension::class);
    }
}
