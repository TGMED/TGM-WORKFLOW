<?php

namespace Database\Factories;

use App\Models\EmployeeAddress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAddress>
 */
class EmployeeAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->randomElement(config('profile.address_types')),
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->randomElement(config('profile.states.NG')),
            'country' => 'NG',
        ];
    }
}
