<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->unique()->randomElement([
                'Platform', 'Field', 'Night shift', 'Front desk', 'Payables',
                'Recruiting', 'Inbound', 'Outbound', 'Fleet', 'Estates',
            ]),
        ];
    }
}
