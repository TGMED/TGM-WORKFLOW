<?php

namespace Database\Factories;

use App\Enums\StaffActionKind;
use App\Models\StaffAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffAction>
 */
class StaffActionFactory extends Factory
{
    protected $model = StaffAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_user_id' => User::factory(),
            'issued_by_id' => User::factory(),
            'kind' => StaffActionKind::Warning,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
        ];
    }

    public function query(): static
    {
        return $this->state([
            'kind' => StaffActionKind::Query,
            'response_due_on' => now()->addDays(3)->toDateString(),
        ]);
    }
}
