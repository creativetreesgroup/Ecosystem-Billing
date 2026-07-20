<?php

namespace Database\Factories;

use App\Domain\Wallet\WalletTransactionType;
use App\Models\Customer;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => WalletTransactionType::TopUp,
            'amount' => 50_000,
            'balance_after' => 50_000,
        ];
    }
}
