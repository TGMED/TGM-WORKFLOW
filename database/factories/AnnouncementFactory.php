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
            // Live notices have been round the company already; the ones that
            // have not are what the publisher looks for.
            'notified_at' => now()->subDay(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => null,
            'notified_at' => null,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'published_at' => now()->addWeek(),
            'notified_at' => null,
        ]);
    }

    /**
     * Live, but the company has not been told yet, which is the state the
     * publisher acts on.
     */
    public function unsent(): static
    {
        return $this->state(fn (array $attributes): array => ['notified_at' => null]);
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
