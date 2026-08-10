<?php

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' leave';

        return [
            'slug' => Str::slug($name),
            'name' => Str::ucfirst($name),
            'description' => fake()->sentence(),
            'days_per_year' => 15,
            'is_paid' => true,
            'is_active' => true,
        ];
    }

    public function uncapped(): static
    {
        return $this->state(fn (array $attributes) => ['days_per_year' => null]);
    }

    public function retired(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
