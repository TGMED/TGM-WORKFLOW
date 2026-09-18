<?php

namespace Database\Factories;

use App\Enums\PolicyCategory;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Policy>
 */
class PolicyFactory extends Factory
{
    protected $model = Policy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'category' => PolicyCategory::Conduct,
            'version' => '1.0',
            'summary' => fake()->sentence(12),
            'file_path' => 'policies/'.fake()->uuid().'.pdf',
            'file_name' => 'policy.pdf',
            'file_size' => 120_000,
            'mime_type' => 'application/pdf',
            'effective_from' => Carbon::now()->subMonth()->toDateString(),
            'is_active' => true,
            'uploaded_by_id' => User::factory(),
        ];
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Written and dated ahead: on file, but not yet the rule.
     */
    public function upcoming(): static
    {
        return $this->state(fn (): array => [
            'effective_from' => Carbon::now()->addMonth()->toDateString(),
        ]);
    }
}
