<?php

namespace Database\Factories;

use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now()->addWeek()->startOfWeek();

        return [
            'user_id' => User::factory(),
            'leave_type_id' => LeaveType::factory(),
            'start_date' => $start,
            'end_date' => $start->copy()->addDays(2),
            'days' => 3,
            'reason' => fake()->sentence(),
            'status' => RequestStatus::Pending,
            'approvals_required' => 1,
        ];
    }

    /**
     * A request raised the way the leave form raises them: cover named, and an
     * approver picked to rule on it once the cover is agreed.
     */
    public function chained(User $reliefOfficer, User $supervisor): static
    {
        return $this->state(fn (): array => [
            'relief_officer_id' => $reliefOfficer->id,
            'supervisor_id' => $supervisor->id,
        ]);
    }
}
