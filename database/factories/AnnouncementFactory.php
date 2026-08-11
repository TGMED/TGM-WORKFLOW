<?php

namespace Database\Factories;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'title' => rtrim(fake()->sentence(5), '.'),
            'body' => fake()->paragraph(),
            'is_pinned' => false,
            'published_at' => now()->subDay(),
            'expires_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => ['published_at' => null]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => now()->addWeek(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function pinned(): static
    {
        return $this->state(fn (array $attributes): array => ['is_pinned' => true]);
    }
}
