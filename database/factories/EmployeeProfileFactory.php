<?php

namespace Database\Factories;

use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeProfile>
 */
class EmployeeProfileFactory extends Factory
{
    /**
     * A finished record. Tests that care about the profile gate reach for the
     * incomplete state below instead.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $states = config('profile.states.NG');

        return [
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(config('profile.genders')),
            'date_of_birth' => fake()->dateTimeBetween('-55 years', '-20 years'),
            'country_of_origin' => 'NG',
            'state_of_origin' => fake()->randomElement($states),
            'local_government' => fake()->city(),
            'marital_status' => fake()->randomElement(config('profile.marital_statuses')),
            'blood_group' => fake()->randomElement(config('profile.blood_groups')),
            'genotype' => fake()->randomElement(config('profile.genotypes')),
            'completed_at' => now(),
        ];
    }

    /**
     * A record someone started but never finished, which is what the profile
     * gate turns people back for.
     */
    public function incomplete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_name' => null,
            'gender' => null,
            'date_of_birth' => null,
            'country_of_origin' => null,
            'state_of_origin' => null,
            'completed_at' => null,
        ]);
    }
}
