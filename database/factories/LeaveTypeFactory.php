<?php

namespace Database\Factories;

use App\Enums\LeaveAnchor;
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
            'min_service_months' => 0,
            'requires_confirmed' => false,
            'requires_evidence' => false,
            'is_paid' => true,
            'is_active' => true,
        ];
    }

    /**
     * A type the policy holds back until somebody has served long enough.
     */
    public function afterMonths(int $months): static
    {
        return $this->state(fn (array $attributes) => ['min_service_months' => $months]);
    }

    /**
     * A type closed to staff still on probation.
     */
    public function confirmedOnly(): static
    {
        return $this->state(fn (array $attributes) => ['requires_confirmed' => true]);
    }

    /**
     * A type that will not be granted without supporting paperwork.
     */
    public function needsEvidence(): static
    {
        return $this->state(fn (array $attributes) => ['requires_evidence' => true]);
    }

    /**
     * An entitlement that lapses, the way birthday leave does.
     */
    public function expiring(LeaveAnchor $anchor = LeaveAnchor::Birthday, int $months = 6): static
    {
        return $this->state(fn (array $attributes) => [
            'anchor' => $anchor,
            'window_months' => $months,
        ]);
    }

    /**
     * A type managers and above draw more of than everyone else.
     */
    public function managerAllowance(int $days): static
    {
        return $this->state(fn (array $attributes) => ['days_per_year_manager' => $days]);
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
