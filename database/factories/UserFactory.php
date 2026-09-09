<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => 'TGM-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'phone' => fake()->numerify('080########'),
            // Left unset: a department is a row now, and most tests do not
            // care which one somebody is in. Those that do reach for
            // inDepartment() below.
            'department_id' => null,
            'position' => fake()->jobTitle(),
            // Far enough back to clear every service gate the policy sets, so
            // a test that does not care about length of service is never
            // turned away by one. Tests that do care set the date themselves.
            'hired_at' => fake()->dateTimeBetween('-4 years', '-2 years'),
            'employment_status' => EmploymentStatus::Confirmed,
            'confirmed_at' => fake()->dateTimeBetween('-4 years', '-1 month'),
            'is_active' => true,
        ];
    }

    /**
     * Everyone gets a finished profile, so the profile gate does not turn
     * every other test back at the door. Tests about the gate itself reach
     * for withoutProfile() or incompleteProfile() below.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->profile()->save(EmployeeProfile::factory()->make(['user_id' => null]));

            // Roles live on a pivot, so they cannot be a column in the
            // definition. Everyone is staff unless a state below says
            // otherwise; those states run after this one and replace the set.
            $user->roles()->sync([Role::idFor(Role::STAFF)]);
        });
    }

    /**
     * Exactly the roles named, replacing the default. Several may be given,
     * which is the point: a person may lead a team as well as hold a role.
     */
    public function roles(string ...$slugs): static
    {
        return $this->afterCreating(function (User $user) use ($slugs): void {
            $user->roles()->sync(
                Role::query()->whereIn('slug', $slugs)->orderBy('id')->pluck('id')->all(),
            );
        });
    }

    /**
     * The roles named, on top of whatever this person already holds.
     */
    public function alsoRoles(string ...$slugs): static
    {
        return $this->afterCreating(function (User $user) use ($slugs): void {
            $user->roles()->syncWithoutDetaching(
                Role::query()->whereIn('slug', $slugs)->pluck('id')->all(),
            );
        });
    }

    /**
     * Someone who has never opened the profile page.
     */
    public function withoutProfile(): static
    {
        // Forced: these users never had a profile at all, and a soft-deleted
        // one would keep its slot in the unique index on user_id.
        return $this->afterCreating(fn (User $user) => $user->profile()->forceDelete());
    }

    /**
     * Someone who started their profile and left required fields blank.
     */
    public function incompleteProfile(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->profile()->forceDelete();
            $user->profile()->save(
                EmployeeProfile::factory()->incomplete()->make(['user_id' => null]),
            );
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superAdmin(): static
    {
        return $this
            ->state(fn (array $attributes) => [
                'position' => 'HR Administrator',
            ])
            ->roles(Role::SUPER_ADMIN);
    }

    /**
     * Somebody placed in a department, and optionally in a team inside it.
     */
    public function inDepartment(Department|int|null $department, Team|int|null $team = null): static
    {
        return $this->state(fn (array $attributes) => [
            'department_id' => $department instanceof Department ? $department->id : $department,
            'team_id' => $team instanceof Team ? $team->id : $team,
        ]);
    }

    public function approver(): static
    {
        return $this->roles(Role::APPROVER);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'deactivated_at' => now()->subDays(fake()->numberBetween(1, 60)),
        ]);
    }

    /**
     * Somebody still inside their probation, which the policy closes a few
     * leave types to.
     */
    public function onProbation(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => EmploymentStatus::Probation,
            'confirmed_at' => null,
        ]);
    }

    /**
     * Somebody who started on the day given, for the service-length rules.
     */
    public function hiredOn(string $date): static
    {
        return $this->state(fn (array $attributes) => ['hired_at' => $date]);
    }
}
