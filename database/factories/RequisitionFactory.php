<?php

namespace Database\Factories;

use App\Enums\RequisitionStatus;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Requisition>
 */
class RequisitionFactory extends Factory
{
    protected $model = Requisition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'title' => fake()->sentence(3),
            'purpose' => fake()->paragraph(),
            'amount' => 150000,
            'bank_code' => '058',
            'bank_name' => 'Guaranty Trust Bank',
            'account_number' => '0123456789',
            'account_name' => 'ADEBAYO SUPPLIES LTD',
            'status' => RequisitionStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => RequisitionStatus::Approved, 'decided_at' => now()]);
    }
}
