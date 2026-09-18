<?php

namespace Database\Factories;

use App\Enums\OutOfOfficeKind;
use App\Enums\RequestStatus;
use App\Models\OutOfOfficeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<OutOfOfficeRequest>
 */
class OutOfOfficeRequestFactory extends Factory
{
    protected $model = OutOfOfficeRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now()->addWeek()->startOfWeek();

        return [
            'user_id' => User::factory(),
            'kind' => OutOfOfficeKind::Remote,
            'start_date' => $start,
            'end_date' => $start->copy()->addDay(),
            'days' => 2,
            'reason' => fake()->sentence(10),
            'status' => RequestStatus::Pending,
            'approvals_required' => 1,
        ];
    }

    /**
     * Out on company business, which asks where and how to be reached.
     */
    public function assignment(): static
    {
        return $this->state(fn (): array => [
            'kind' => OutOfOfficeKind::Assignment,
            'destination' => fake()->city().' branch',
            'contact_number' => fake()->numerify('080########'),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => RequestStatus::Approved,
            'decided_at' => Carbon::now(),
        ]);
    }
}
