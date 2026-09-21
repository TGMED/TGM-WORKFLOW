<?php

namespace Database\Factories;

use App\Models\PublicHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PublicHoliday>
 */
class PublicHolidayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Independence Day', 'Workers Day', 'Democracy Day', 'Christmas Day', 'Boxing Day']),
            'date' => Carbon::now()->addDays(fake()->unique()->numberBetween(1, 300))->toDateString(),
        ];
    }
}
