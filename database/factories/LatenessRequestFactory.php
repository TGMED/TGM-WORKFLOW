<?php

namespace Database\Factories;

use App\Enums\RequestStatus;
use App\Models\LatenessRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LatenessRequest>
 */
class LatenessRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'work_date' => Carbon::now()->subDay(),
            'minutes_late' => fake()->numberBetween(5, 90),
            'reason' => fake()->sentence(10),
            'status' => RequestStatus::Pending,
            'approvals_required' => 1,
        ];
    }
}
