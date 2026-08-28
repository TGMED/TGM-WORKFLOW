<?php

namespace Tests\Feature;

use App\Enums\AttemptResult;
use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClockInTest extends TestCase
{
    use RefreshDatabase;

    /** Victoria Island, Lagos. */
    private const OFFICE = ['lat' => 6.4281, 'lng' => 3.4219];

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create([
            'name' => 'TGM Head Office',
            'address' => '1 Adeola Odeku Street',
            'latitude' => self::OFFICE['lat'],
            'longitude' => self::OFFICE['lng'],
            'radius_meters' => 150,
            'max_accuracy_meters' => 200,
            'work_starts_at' => '09:00:00',
            'grace_minutes' => 10,
            'timezone' => 'Africa/Lagos',
            'workdays' => [1, 2, 3, 4, 5],
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function atOffice(array $overrides = []): array
    {
        return array_merge([
            'latitude' => self::OFFICE['lat'],
            'longitude' => self::OFFICE['lng'],
            'accuracy' => 20,
        ], $overrides);
    }

    public function test_a_punch_inside_the_fence_is_accepted(): void
    {
        // 08:30 Lagos, comfortably before the 09:00 start.
        Carbon::setTestNow(Carbon::parse('2026-06-15 07:30:00', 'UTC'));

        $user = $this->staff();

        $this->actingAs($user)->post('/clock/in', $this->atOffice());

        $attendance = Attendance::query()->firstOrFail();

        $this->assertNotNull($attendance->clocked_in_at);
        $this->assertSame(AttendanceStatus::OnTime, $attendance->status);
        $this->assertSame(0, $attendance->late_minutes);
    }

    public function test_arriving_after_the_grace_window_is_marked_late(): void
    {
        // 09:25 Lagos, 25 minutes past a 09:00 start with 10 minutes grace.
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:25:00', 'UTC'));

        $user = $this->staff();

        $this->actingAs($user)->post('/clock/in', $this->atOffice());

        $attendance = Attendance::query()->firstOrFail();

        $this->assertSame(AttendanceStatus::Late, $attendance->status);
        $this->assertSame(25, $attendance->late_minutes);
    }

    public function test_arriving_inside_the_grace_window_is_not_late(): void
    {
        // 09:08 Lagos, inside the 10 minute grace window.
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:08:00', 'UTC'));

        $this->actingAs($this->staff())
            ->post('/clock/in', $this->atOffice());

        $attendance = Attendance::query()->firstOrFail();

        $this->assertSame(AttendanceStatus::Grace, $attendance->status);
        $this->assertFalse($attendance->status->isLate());
        $this->assertSame(0, $attendance->late_minutes);
    }

    public function test_arriving_on_the_last_minute_of_grace_is_still_not_late(): void
    {
        // 09:10 Lagos, the final minute that still counts.
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:10:00', 'UTC'));

        $this->actingAs($this->staff())
            ->post('/clock/in', $this->atOffice());

        $this->assertSame(
            AttendanceStatus::Grace,
            Attendance::query()->firstOrFail()->status,
        );
    }

    public function test_the_first_minute_after_grace_is_late(): void
    {
        // 09:11 Lagos, one minute past the cutoff.
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:11:00', 'UTC'));

        $this->actingAs($this->staff())
            ->post('/clock/in', $this->atOffice());

        $attendance = Attendance::query()->firstOrFail();

        $this->assertSame(AttendanceStatus::Late, $attendance->status);
        $this->assertSame(11, $attendance->late_minutes);
    }

    public function test_a_punch_outside_the_fence_is_rejected_but_still_logged(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/clock/in', $this->atOffice([
            'latitude' => 6.5000,
            'longitude' => 3.5000,
        ]));

        $this->assertSame(0, Attendance::query()->count());

        $attempt = ClockAttempt::query()->firstOrFail();

        $this->assertSame(AttemptResult::OutOfRange, $attempt->result);
        $this->assertSame($user->id, $attempt->user_id);
        $this->assertGreaterThan(150, $attempt->distance_meters);
    }

    public function test_a_punch_without_coordinates_is_rejected_but_still_logged(): void
    {
        $this->actingAs($this->staff())->post('/clock/in', []);

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame(
            AttemptResult::NoLocation,
            ClockAttempt::query()->firstOrFail()->result,
        );
    }

    public function test_a_vague_gps_reading_is_rejected(): void
    {
        $this->actingAs($this->staff())
            ->post('/clock/in', $this->atOffice(['accuracy' => 900]));

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame(
            AttemptResult::LowAccuracy,
            ClockAttempt::query()->firstOrFail()->result,
        );
    }

    public function test_clocking_in_twice_is_rejected(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/clock/in', $this->atOffice());
        $this->actingAs($user)->post('/clock/in', $this->atOffice());

        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(
            AttemptResult::Duplicate,
            ClockAttempt::query()->latest('id')->firstOrFail()->result,
        );
    }

    public function test_clocking_out_records_hours_worked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00', 'UTC'));

        $user = $this->staff();
        $this->actingAs($user)->post('/clock/in', $this->atOffice());

        Carbon::setTestNow(Carbon::parse('2026-06-15 16:00:00', 'UTC'));
        $this->actingAs($user)->post('/clock/out', $this->atOffice());

        $attendance = Attendance::query()->firstOrFail();

        $this->assertNotNull($attendance->clocked_out_at);
        $this->assertSame(480, $attendance->worked_minutes);
    }

    public function test_clocking_out_without_clocking_in_is_rejected(): void
    {
        $this->actingAs($this->staff())
            ->post('/clock/out', $this->atOffice());

        $this->assertSame(
            AttemptResult::NotClockedIn,
            ClockAttempt::query()->firstOrFail()->result,
        );
    }

    public function test_punches_are_blocked_until_a_location_is_configured(): void
    {
        $this->location->update(['latitude' => null, 'longitude' => null]);

        $this->actingAs($this->staff())
            ->post('/clock/in', $this->atOffice());

        $this->assertSame(0, Attendance::query()->count());
        $this->assertSame(
            AttemptResult::NoLocationConfigured,
            ClockAttempt::query()->firstOrFail()->result,
        );
    }

    public function test_every_attempt_records_who_where_and_how_far(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/clock/in', $this->atOffice([
            'latitude' => 6.4291,
            'accuracy' => 33,
        ]));

        $attempt = ClockAttempt::query()->firstOrFail();

        $this->assertSame($user->id, $attempt->user_id);
        $this->assertSame(33, $attempt->accuracy_meters);
        $this->assertEqualsWithDelta(6.4291, $attempt->latitude, 0.00001);
        // 0.001 degrees of latitude is roughly 111 metres.
        $this->assertEqualsWithDelta(111, $attempt->distance_meters, 3);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
