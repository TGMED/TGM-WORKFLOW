<?php

namespace Database\Factories;

use App\Models\EmployeeAddress;
use App\Models\EmployeeProfile;
use App\Models\Role;
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
            'role_id' => fn (): int => Role::idFor(Role::STAFF),
            'phone' => fake()->numerify('080########'),
            'department' => fake()->randomElement([
                'Engineering', 'Operations', 'Finance', 'People', 'Sales', 'Support',
            ]),
            'position' => fake()->jobTitle(),
            'hired_at' => fake()->dateTimeBetween('-4 years', '-1 month'),
            'is_active' => true,
        ];
    }

    /**
     * Everyone gets a finished record, so the profile gate does not turn
     * every other test back at the door. That means an address as well as a
     * profile row, since the gate holds people to both. Tests about the gate
     * itself reach for withoutProfile() or incompleteProfile() below.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->profile()->save(EmployeeProfile::factory()->make(['user_id' => null]));
            $user->addresses()->save(EmployeeAddress::factory()->make(['user_id' => null]));
        });
    }

    /**
     * Someone who has never opened the profile page.
     */
    public function withoutProfile(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->profile()->delete();
            $user->addresses()->delete();
        });
    }

    /**
     * Someone who started their profile and left required fields blank.
     */
    public function incompleteProfile(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->profile()->delete();
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
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::idFor(Role::SUPER_ADMIN),
            'department' => 'People',
            'position' => 'HR Administrator',
        ]);
    }

    public function approver(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::idFor(Role::APPROVER),
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'deactivated_at' => now()->subDays(fake()->numberBetween(1, 60)),
        ]);
    }
}
