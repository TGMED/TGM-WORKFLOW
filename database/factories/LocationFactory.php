<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Office',
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'latitude' => fake()->latitude(6.3, 6.6),
            'longitude' => fake()->longitude(3.2, 3.6),
            'radius_meters' => 150,
            'max_accuracy_meters' => 200,
            'work_starts_at' => '09:00:00',
            'work_ends_at' => '17:00:00',
            'grace_minutes' => 10,
            'break_minutes' => 60,
            'workdays' => [1, 2, 3, 4, 5],
            'timezone' => 'Africa/Lagos',
            'is_active' => true,
            'accepts_signups' => true,
        ];
    }

    public function closedToSignups(): static
    {
        return $this->state(fn (array $attributes) => ['accepts_signups' => false]);
    }

    public function retired(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
