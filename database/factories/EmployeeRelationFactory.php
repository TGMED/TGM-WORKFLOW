<?php

namespace Database\Factories;

use App\Enums\RelationKind;
use App\Models\EmployeeRelation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeRelation>
 */
class EmployeeRelationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => fake()->randomElement(RelationKind::cases()),
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(config('profile.relationships')),
            'phone' => '+234'.fake()->numerify('80########'),
            'email' => fake()->safeEmail(),
        ];
    }
}
