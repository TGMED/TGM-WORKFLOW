<?php

namespace Tests\Feature;

use App\Enums\AttemptResult;
use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChooseWorkLocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amara Nwosu',
            'email' => 'amara@tgm.test',
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ], $overrides);
    }

    public function test_someone_can_sign_up_before_any_location_exists(): void
    {
        $this->assertSame(0, Location::query()->count());

        $this->post('/register', $this->signup())->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'amara@tgm.test')->firstOrFail();

        $this->assertNull($user->location_id);
        $this->assertTrue($user->is_active);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_signup_page_renders_with_no_locations(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_someone_can_sign_up_and_skip_the_location(): void
    {
        Location::factory()->create();

        $this->post('/register', $this->signup())->assertRedirect('/dashboard');

        $this->assertNull(
            User::query()->where('email', 'amara@tgm.test')->firstOrFail()->location_id,
        );
    }

    public function test_a_user_without_a_location_still_cannot_clock_in(): void
    {
        $user = User::factory()->create(['location_id' => null]);

        $this->actingAs($user)->post('/clock/in', [
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'accuracy' => 20,
        ]);

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame(
            AttemptResult::NoLocationAssigned,
            ClockAttempt::query()->firstOrFail()->result,
        );
    }

    public function test_a_user_can_claim_a_site_and_then_clock_in(): void
    {
        $location = Location::factory()->create([
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'radius_meters' => 150,
        ]);

        $user = User::factory()->create(['location_id' => null]);

        $this->actingAs($user)
            ->post('/work-location', ['location_id' => $location->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($location->id, $user->refresh()->location_id);

        $this->actingAs($user)->post('/clock/in', [
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'accuracy' => 20,
        ]);

        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_the_dashboard_offers_the_choices_when_there_is_no_site(): void
    {
        Location::factory()->create(['name' => 'TGM Ikeja']);
        Location::factory()->retired()->create();
        Location::factory()->closedToSignups()->create();

        $user = User::factory()->create(['location_id' => null]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('location', null)
                    ->has('selectableLocations', 1)
                    ->where('selectableLocations.0.name', 'TGM Ikeja'),
            );
    }

    public function test_a_retired_site_cannot_be_claimed(): void
    {
        $location = Location::factory()->retired()->create();
        $user = User::factory()->create(['location_id' => null]);

        $this->actingAs($user)
            ->post('/work-location', ['location_id' => $location->id])
            ->assertSessionHasErrors('location_id');

        $this->assertNull($user->refresh()->location_id);
    }

    /**
     * The geofence would be meaningless if staff could hop between sites.
     */
    public function test_a_user_cannot_move_themselves_once_a_site_is_set(): void
    {
        $first = Location::factory()->create();
        $second = Location::factory()->create();

        $user = User::factory()->create(['location_id' => $first->id]);

        $this->actingAs($user)
            ->post('/work-location', ['location_id' => $second->id])
            ->assertForbidden();

        $this->assertSame($first->id, $user->refresh()->location_id);
    }

    public function test_an_admin_can_still_move_staff_between_sites(): void
    {
        $first = Location::factory()->create();
        $second = Location::factory()->create();

        $admin = User::factory()->superAdmin()->create();
        $staff = User::factory()->create(['location_id' => $first->id]);

        $this->actingAs($admin)
            ->put("/admin/staff/{$staff->id}", [
                'name' => $staff->name,
                'email' => $staff->email,
                'roles' => ['staff'],
                'location_id' => $second->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($second->id, $staff->refresh()->location_id);
    }

    public function test_admins_have_no_use_for_the_claim_endpoint(): void
    {
        $location = Location::factory()->create();
        $admin = User::factory()->superAdmin()->create(['location_id' => null]);

        $this->actingAs($admin)
            ->post('/work-location', ['location_id' => $location->id])
            ->assertForbidden();
    }
}
