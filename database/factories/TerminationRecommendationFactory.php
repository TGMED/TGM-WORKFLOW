<?php

namespace Database\Factories;

use App\Enums\RecommendationStatus;
use App\Models\TerminationRecommendation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TerminationRecommendation>
 */
class TerminationRecommendationFactory extends Factory
{
    protected $model = TerminationRecommendation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_user_id' => User::factory(),
            'raised_by_id' => User::factory(),
            'grounds' => fake()->paragraph(),
            'occurrence' => 1,
            'status' => RecommendationStatus::Pending,
        ];
    }
}
