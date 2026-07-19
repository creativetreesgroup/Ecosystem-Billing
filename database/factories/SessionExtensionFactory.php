<?php

namespace Database\Factories;

use App\Models\RentalSession;
use App\Models\SessionExtension;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionExtension>
 */
class SessionExtensionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rental_session_id' => RentalSession::factory(),
            'added_minutes' => 60,
            'amount' => 5000,
            'user_id' => User::factory(),
        ];
    }
}
