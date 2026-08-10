<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amara Nwosu',
            'email' => 'amara@tgm.test',
            'department' => 'Engineering',
            'position' => 'Technician',
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ], $overrides);
    }

    /**
     * The signup form mirrors these rules as a live checklist, so they have to
     * hold outside production too.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function passwordProvider(): array
    {
        return [
            'eight chars is enough' => ['Ab3!wxyz', true],
            'seven chars is not' => ['Ab3!wxy', false],
            'needs a number' => ['Abcd!wxyz', false],
            'needs a symbol' => ['Abcd3wxyz', false],
            'needs mixed case' => ['abcd3!wxyz', false],
        ];
    }

    #[DataProvider('passwordProvider')]
    public function test_signup_enforces_the_password_rules(string $password, bool $accepted): void
    {
        $response = $this->post('/register', $this->payload([
            'password' => $password,
            'password_confirmation' => $password,
        ]));

        $accepted
            ? $response->assertSessionHasNoErrors()
            : $response->assertSessionHasErrors('password');
    }

    public function test_the_signup_screen_renders(): void
    {
        Location::factory()->create();

        $this->get('/register')->assertOk();
    }

    public function test_someone_can_sign_up_and_is_tied_to_the_chosen_location(): void
    {
        $location = Location::factory()->create(['name' => 'TGM Ikeja']);

        $this->post('/register', $this->payload(['location_id' => $location->id]))
            ->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'amara@tgm.test')->firstOrFail();

        $this->assertSame($location->id, $user->location_id);
        $this->assertSame(Role::STAFF, $user->role->slug);
        $this->assertTrue($user->is_active);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * A site is optional at signup, but an unknown one is still rejected.
     */
    public function test_a_work_location_may_be_left_out(): void
    {
        Location::factory()->create();

        $this->post('/register', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/dashboard');

        $this->assertNull(
            User::query()->where('email', 'amara@tgm.test')->firstOrFail()->location_id,
        );
    }

    public function test_an_unknown_work_location_is_rejected(): void
    {
        Location::factory()->create();

        $this->post('/register', $this->payload(['location_id' => 9999]))
            ->assertSessionHasErrors('location_id');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'amara@tgm.test']);
    }

    public function test_a_retired_location_cannot_be_chosen(): void
    {
        $location = Location::factory()->retired()->create();

        $this->post('/register', $this->payload(['location_id' => $location->id]))
            ->assertSessionHasErrors('location_id');

        $this->assertGuest();
    }

    public function test_a_location_closed_to_signups_cannot_be_chosen(): void
    {
        $location = Location::factory()->closedToSignups()->create();

        $this->post('/register', $this->payload(['location_id' => $location->id]))
            ->assertSessionHasErrors('location_id');

        $this->assertGuest();
    }

    public function test_signups_can_be_made_to_wait_for_approval(): void
    {
        config()->set('hr.activate_signups_immediately', false);

        $location = Location::factory()->create();

        $this->post('/register', $this->payload(['location_id' => $location->id]))
            ->assertRedirect('/login');

        $user = User::query()->where('email', 'amara@tgm.test')->firstOrFail();

        $this->assertFalse($user->is_active);
        $this->assertGuest();
    }

    public function test_signups_cannot_take_the_super_admin_role(): void
    {
        $location = Location::factory()->create();

        $this->post('/register', $this->payload([
            'location_id' => $location->id,
            'role' => Role::SUPER_ADMIN,
        ]));

        $user = User::query()->where('email', 'amara@tgm.test')->firstOrFail();

        $this->assertSame(Role::STAFF, $user->role->slug);
    }

    public function test_an_existing_email_is_rejected(): void
    {
        $location = Location::factory()->create();
        User::factory()->create(['email' => 'amara@tgm.test']);

        $this->post('/register', $this->payload(['location_id' => $location->id]))
            ->assertSessionHasErrors('email');
    }

    public function test_signed_in_users_are_kept_away_from_signup(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/register')
            ->assertRedirect();
    }
}
