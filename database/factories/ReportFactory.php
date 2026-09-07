<?php

namespace Database\Factories;

use App\Enums\ReportCategory;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject_user_id' => null,
            'subject_name' => null,
            'category' => ReportCategory::Misconduct,
            'subject' => rtrim(fake()->sentence(5), '.'),
            'body' => fake()->paragraph(),
            'occurred_on' => now()->subDays(3)->toDateString(),
            'place' => fake()->city(),
            'evidence_path' => null,
            'evidence_name' => null,
            'status' => ReportStatus::Submitted,
            'handled_by_id' => null,
            'handled_at' => null,
            'resolution_note' => null,
        ];
    }

    public function about(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'subject_user_id' => $user->id,
        ]);
    }

    public function category(ReportCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => $category,
        ]);
    }

    public function underReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReportStatus::UnderReview,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReportStatus::Resolved,
            'handled_at' => now()->subDay(),
            'resolution_note' => 'Investigated and dealt with.',
        ]);
    }

    public function withEvidence(): static
    {
        return $this->state(fn (array $attributes): array => [
            'evidence_path' => 'report-evidence/example.pdf',
            'evidence_name' => 'evidence.pdf',
        ]);
    }
}
