<?php

namespace Database\Factories;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Models\Payment;
use App\Models\RentalSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'rental_session_id' => RentalSession::factory(),
            'method' => PaymentMethod::Cash,
            'status' => PaymentStatus::Paid,
            'amount' => fake()->numberBetween(5, 60) * 1000,
        ];
    }

    public function qrisPending(): static
    {
        return $this->state(fn (): array => [
            'method' => PaymentMethod::Qris,
            'status' => PaymentStatus::Pending,
            'reference' => 'ORDER-'.fake()->unique()->numerify('##########'),
        ]);
    }

    public function transferAwaitingVerification(): static
    {
        return $this->state(fn (): array => [
            'method' => PaymentMethod::Transfer,
            'status' => PaymentStatus::AwaitingVerification,
            'proof_path' => 'payment-proofs/'.fake()->uuid().'.jpg',
        ]);
    }
}
