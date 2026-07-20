<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '08'.fake()->unique()->numerify('##########'),
            'pin_hash' => '123456',
            'balance' => 0,
            'is_active' => true,
        ];
    }

    public function withBalance(int $balance): static
    {
        // Lewat kolom langsung, BUKAN lewat Wallet: factory tidak boleh
        // menghasilkan baris buku besar palsu yang lalu dipakai test lain
        // sebagai bukti bahwa uang pernah masuk.
        return $this->state(fn (): array => ['balance' => $balance]);
    }
}
