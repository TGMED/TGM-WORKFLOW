<?php

namespace Database\Factories;

use App\Models\LeaveAdjustment;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LeaveAdjustment>
 */
class LeaveAdjustmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'leave_type_id' => LeaveType::factory(),
            'year' => Carbon::now()->year,
            'days' => fake()->numberBetween(1, 5),
            'reason' => 'Carried over from last year',
            'created_by' => User::factory()->superAdmin(),
        ];
    }

    public function deducting(): static
    {
        return $this->state(fn (array $attributes) => [
            'days' => -fake()->numberBetween(1, 5),
            'reason' => 'Correction to an over-granted balance',
        ]);
    }
}
