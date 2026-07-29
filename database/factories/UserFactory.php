<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function owner(): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles(['super_admin']));
    }

    /**
     * Peran Shield ikut menempel pada pengguna biasa.
     *
     * Otorisasi dibaca dari database sekarang; pengguna tanpa peran tidak bisa
     * apa-apa, dan test yang memakainya akan menguji panel kosong alih-alih
     * panel yang benar-benar dipakai kasir.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->roles()->doesntExist()) {
                $user->assignRole('staf_operasional');
            }
        });
    }
}
