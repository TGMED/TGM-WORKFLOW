<?php

namespace Tests\Feature;

use App\Enums\AttemptResult;
use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'TGM Ikeja',
            'address' => '12 Allen Avenue, Ikeja',
            'city' => 'Lagos',
            'latitude' => 6.6018,
            'longitude' => 3.3515,
            'radius_meters' => 200,
            'max_accuracy_meters' => 200,
            'work_starts_at' => '08:30',
            'work_ends_at' => '16:30',
            'grace_minutes' => 15,
            'break_minutes' => 45,
            'workdays' => [1, 2, 3, 4, 5],
            'timezone' => 'Africa/Lagos',
            'accepts_signups' => true,
        ], $overrides);
    }

    public function test_super_admins_can_create_a_location(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/locations', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('locations', [
            'name' => 'TGM Ikeja',
            'address' => '12 Allen Avenue, Ikeja',
            'radius_meters' => 200,
        ]);
    }

    public function test_a_location_needs_an_address_and_coordinates(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/locations', $this->payload([
                'address' => '',
                'latitude' => null,
                'longitude' => null,
            ]))
            ->assertSessionHasErrors(['address', 'latitude', 'longitude']);
    }

    public function test_staff_cannot_manage_locations(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->post('/admin/locations', $this->payload())
            ->assertForbidden();
    }

    public function test_a_location_can_be_retired_and_restored(): void
    {
        $location = Location::factory()->create();

        $this->actingAs($this->admin())
            ->patch("/admin/locations/{$location->id}/toggle")
            ->assertSessionHasNoErrors();

        $this->assertFalse($location->refresh()->is_active);

        $this->actingAs($this->admin())->patch("/admin/locations/{$location->id}/toggle");

        $this->assertTrue($location->refresh()->is_active);
    }

    public function test_staff_can_be_moved_off_a_location_in_bulk(): void
    {
        $from = Location::factory()->create();
        $to = Location::factory()->create();

        User::factory(3)->create(['location_id' => $from->id]);

        $this->actingAs($this->admin())
            ->patch("/admin/locations/{$from->id}/reassign", ['to_location_id' => $to->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, User::query()->where('location_id', $from->id)->count());
        $this->assertSame(3, User::query()->where('location_id', $to->id)->count());
    }

    /**
     * The whole point of per-site hours: the same arrival time is late at one
     * location and on time at another.
     */
    public function test_lateness_is_judged_against_the_staff_members_own_site(): void
    {
        $early = Location::factory()->create([
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'work_starts_at' => '08:00:00',
            'grace_minutes' => 5,
            'timezone' => 'Africa/Lagos',
        ]);

        $late = Location::factory()->create([
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'work_starts_at' => '09:00:00',
            'grace_minutes' => 10,
            'timezone' => 'Africa/Lagos',
        ]);

        // 08:40 Lagos.
        Carbon::setTestNow(Carbon::parse('2026-06-15 07:40:00', 'UTC'));

        $punch = ['latitude' => 6.4281, 'longitude' => 3.4219, 'accuracy' => 20];

        $earlyStaff = User::factory()->create(['location_id' => $early->id]);
        $lateStaff = User::factory()->create(['location_id' => $late->id]);

        $this->actingAs($earlyStaff)->post('/clock/in', $punch);
        $this->actingAs($lateStaff)->post('/clock/in', $punch);

        $earlyRecord = Attendance::query()->where('user_id', $earlyStaff->id)->firstOrFail();
        $lateRecord = Attendance::query()->where('user_id', $lateStaff->id)->firstOrFail();

        // 08:40 is 40 minutes past an 08:00 start.
        $this->assertSame('late', $earlyRecord->status->value);
        $this->assertSame(40, $earlyRecord->late_minutes);

        // The same moment is 20 minutes before a 09:00 start.
        $this->assertSame('on_time', $lateRecord->status->value);
        $this->assertSame(0, $lateRecord->late_minutes);
    }

    public function test_a_punch_is_measured_against_the_assigned_site_not_the_nearest(): void
    {
        $assigned = Location::factory()->create([
            'latitude' => 9.0846,
            'longitude' => 7.4951,
            'radius_meters' => 150,
        ]);

        // Another site exists exactly where the person is standing.
        Location::factory()->create([
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'radius_meters' => 150,
        ]);

        $user = User::factory()->create(['location_id' => $assigned->id]);

        $this->actingAs($user)->post('/clock/in', [
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'accuracy' => 20,
        ]);

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame(
            AttemptResult::OutOfRange,
            ClockAttempt::query()->firstOrFail()->result,
        );
    }

    public function test_staff_without_a_location_cannot_clock_in(): void
    {
        $user = User::factory()->create(['location_id' => null]);

        $this->actingAs($user)->post('/clock/in', [
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'accuracy' => 20,
        ]);

        $this->assertSame(0, Attendance::query()->count());

        $attempt = ClockAttempt::query()->firstOrFail();

        $this->assertSame(AttemptResult::NoLocationAssigned, $attempt->result);
        $this->assertNull($attempt->location_id);
    }

    public function test_a_punch_records_the_site_it_was_measured_against(): void
    {
        $location = Location::factory()->create([
            'latitude' => 6.4281,
            'longitude' => 3.4219,
        ]);

        $user = User::factory()->create(['location_id' => $location->id]);

        $this->actingAs($user)->post('/clock/in', [
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'accuracy' => 20,
        ]);

        $this->assertSame($location->id, Attendance::query()->firstOrFail()->location_id);
        $this->assertSame($location->id, ClockAttempt::query()->firstOrFail()->location_id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
