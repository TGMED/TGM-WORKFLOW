<?php

namespace Database\Factories;

use App\Enums\ReviewStanding;
use App\Enums\ReviewVisibility;
use App\Models\PerformanceReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceReview>
 */
class PerformanceReviewFactory extends Factory
{
    protected $model = PerformanceReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_user_id' => User::factory(),
            'reviewer_id' => User::factory(),
            'standing' => ReviewStanding::Peer,
            'visibility' => ReviewVisibility::Public,
            'rating' => fake()->numberBetween(1, 5),
            'body' => fake()->paragraph(),
        ];
    }

    public function private(): static
    {
        return $this->state(['visibility' => ReviewVisibility::Private]);
    }
}
