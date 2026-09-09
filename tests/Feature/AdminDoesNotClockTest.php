<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDoesNotClockTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create([
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'radius_meters' => 150,
        ]);
    }

    /**
     * @return array<string, float|int>
     */
    private function onSite(): array
    {
        return ['latitude' => 6.4281, 'longitude' => 3.4219, 'accuracy' => 20];
    }

    public function test_an_admin_standing_on_the_pin_still_cannot_clock_in(): void
    {
        $admin = User::factory()->superAdmin()->create([
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($admin)
            ->post('/clock/in', $this->onSite())
            ->assertForbidden();

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame(0, ClockAttempt::query()->count());
    }

    public function test_an_admin_cannot_clock_out(): void
    {
        $admin = User::factory()->superAdmin()->create([
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($admin)
            ->post('/clock/out', $this->onSite())
            ->assertForbidden();
    }

    public function test_an_admin_is_sent_away_from_the_personal_attendance_page(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get('/attendance')
            ->assertRedirect('/dashboard');
    }

    public function test_staff_keep_their_attendance_page_and_clock(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($staff)->get('/attendance')->assertOk();
        $this->actingAs($staff)->post('/clock/in', $this->onSite());

        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_the_admin_dashboard_is_the_company_console(): void
    {
        $admin = User::factory()->superAdmin()->create([
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('clocksIn', false)
                    ->where('location', null)
                    ->where('today', null)
                    ->where('stats', null)
                    ->has('metrics'),
            );
    }

    public function test_a_staff_dashboard_still_carries_the_punch_dial(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('clocksIn', true)
                    ->has('location')
                    ->has('stats')
                    // Ordinary staff get no company console and no group of
                    // their own to look after.
                    ->where('metrics', null)
                    ->where('group', null),
            );
    }

    public function test_an_admin_may_be_created_without_a_work_location(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post('/admin/staff', [
            'name' => 'Second Admin',
            'email' => 'second@tgm.test',
            'roles' => [Role::SUPER_ADMIN],
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'second@tgm.test',
            'location_id' => null,
        ]);
    }

    public function test_staff_still_require_a_work_location(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post('/admin/staff', [
            'name' => 'No Site',
            'email' => 'nosite@tgm.test',
            'roles' => [Role::STAFF],
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ])->assertSessionHasErrors('location_id');
    }

    public function test_site_headcounts_ignore_administrators(): void
    {
        $admin = User::factory()->superAdmin()->create([
            'location_id' => $this->location->id,
        ]);
        User::factory(3)->create(['location_id' => $this->location->id]);

        $this->actingAs($admin)
            ->get('/admin/locations')
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page->where('locations.0.active_staff_count', 3),
            );
    }
}
