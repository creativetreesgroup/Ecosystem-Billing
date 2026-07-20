<?php

namespace Database\Factories;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RentalSession>
 */
class RentalSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'opened_by' => User::factory(),
            'customer_name' => fake()->firstName(),
            'type' => SessionType::Open,
            'started_at' => now(),
            'ends_at' => null,
            'status' => SessionStatus::Active,
            'expiry_token' => (string) Str::uuid(),
            'base_amount' => 0,
            'extra_amount' => 0,
        ];
    }

    public function package(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => SessionType::Package,
        ]);
    }

    /**
     * Sesi selesai LENGKAP dengan baris pembayarannya.
     *
     * Sejak laporan hanya menghitung sesi yang punya pembayaran Lunas, sesi
     * "completed" tanpa baris pembayaran adalah keadaan yang tidak pernah bisa
     * terjadi lewat aplikasi — CompleteSessionAction selalu membuatnya. Factory
     * yang menghasilkan keadaan mustahil membuat test menguji dunia yang tidak
     * ada, dan diam-diam menutupi bug di dunia yang ada.
     */
    public function completed(): static
    {
        return $this
            ->state(fn (array $attributes) => [
                'status' => SessionStatus::Completed,
                'ended_at' => now(),
                'total_amount' => 0,
            ])
            ->afterCreating(fn (RentalSession $session) => $session->payments()->create([
                'method' => $session->payment_method ?? PaymentMethod::Cash,
                'status' => PaymentStatus::Paid,
                'amount' => (int) $session->total_amount,
                'verified_at' => $session->ended_at,
            ]));
    }

    /**
     * Sesi selesai yang uangnya BELUM terbukti masuk — mis. bukti transfer
     * masih menunggu diperiksa kasir. Tidak boleh muncul di laporan.
     */
    public function completedAwaitingPayment(): static
    {
        return $this
            ->state(fn (array $attributes) => [
                'status' => SessionStatus::Completed,
                'ended_at' => now(),
                'payment_method' => PaymentMethod::Transfer,
            ])
            ->afterCreating(fn (RentalSession $session) => $session->payments()->create([
                'method' => PaymentMethod::Transfer,
                'status' => PaymentStatus::AwaitingVerification,
                'amount' => (int) $session->total_amount,
                'proof_path' => 'payment-proofs/uji.jpg',
            ]));
    }
}
