<?php

namespace Database\Factories;

use App\Enums\OffenceSeverity;
use App\Enums\SanctionAction;
use App\Models\Offence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offence>
 */
class OffenceFactory extends Factory
{
    protected $model = Offence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->lexify('?')).fake()->numberBetween(1, 9),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(12),
            'severity' => OffenceSeverity::Minor,
            'is_active' => true,
        ];
    }

    public function gross(): static
    {
        return $this->state(fn (): array => ['severity' => OffenceSeverity::Gross]);
    }

    /**
     * With the usual three-rung ladder written against it.
     */
    public function withLadder(): static
    {
        return $this->afterCreating(function (Offence $offence): void {
            foreach ([
                1 => SanctionAction::VerbalWarning,
                2 => SanctionAction::WrittenWarning,
                3 => SanctionAction::Dismissal,
            ] as $occurrence => $action) {
                $offence->sanctions()->create([
                    'occurrence' => $occurrence,
                    'action' => $action,
                ]);
            }
        });
    }
}
