<?php

namespace Database\Factories;

use App\Models\LeaveRestrictedPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LeaveRestrictedPeriod>
 */
class LeaveRestrictedPeriodFactory extends Factory
{
    protected $model = LeaveRestrictedPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now()->addWeek();

        return [
            'name' => 'Year-end close',
            'reason' => 'The books are being closed and everyone is needed.',
            'start_date' => $start,
            'end_date' => $start->copy()->addWeek(),
            'exempt_marital_statuses' => [],
        ];
    }

    /**
     * @param  array<int, string>  $statuses
     */
    public function exempting(array $statuses): static
    {
        return $this->state(fn (): array => ['exempt_marital_statuses' => $statuses]);
    }
}
