<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BreakTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create([
            'timezone' => 'Africa/Lagos',
            'break_minutes' => 60,
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    private function clockedIn(User $user, ?Carbon $at = null): Attendance
    {
        $at ??= Carbon::now()->subHours(3);

        return Attendance::query()->create([
            'user_id' => $user->id,
            'location_id' => $this->location->id,
            'work_date' => Carbon::now()->setTimezone($this->location->timezone)->toDateString(),
            'clocked_in_at' => $at,
            'status' => AttendanceStatus::OnTime,
            'late_minutes' => 0,
        ]);
    }

    public function test_staff_can_start_and_end_a_break(): void
    {
        $user = $this->staff();
        $attendance = $this->clockedIn($user);

        Carbon::setTestNow(Carbon::now());

        $this->actingAs($user)->post('/break/start');

        $this->assertNotNull($attendance->refresh()->break_started_at);
        $this->assertTrue($attendance->isOnBreak());

        Carbon::setTestNow(Carbon::now()->addMinutes(43));

        $this->actingAs($user)->post('/break/end');

        $attendance->refresh();

        $this->assertSame(43, $attendance->break_minutes);
        $this->assertFalse($attendance->isOnBreak());
        $this->assertTrue($attendance->hasTakenBreak());

        Carbon::setTestNow();
    }

    public function test_a_break_cannot_start_before_clocking_in(): void
    {
        $this->actingAs($this->staff())->post('/break/start');

        $this->assertSame(0, Attendance::query()->whereNotNull('break_started_at')->count());
    }

    public function test_only_one_break_is_allowed_a_day(): void
    {
        $user = $this->staff();
        $attendance = $this->clockedIn($user);

        $attendance->forceFill([
            'break_started_at' => Carbon::now()->subHour(),
            'break_ended_at' => Carbon::now()->subMinutes(20),
            'break_minutes' => 40,
        ])->save();

        $this->actingAs($user)->post('/break/start');

        $this->assertSame(40, $attendance->refresh()->break_minutes);
    }

    public function test_a_site_can_switch_breaks_off(): void
    {
        $this->location->update(['break_minutes' => 0]);

        $user = $this->staff();
        $attendance = $this->clockedIn($user);

        $this->actingAs($user)->post('/break/start');

        $this->assertNull($attendance->refresh()->break_started_at);
    }

    public function test_going_over_the_limit_is_recorded_not_blocked(): void
    {
        $user = $this->staff();
        $attendance = $this->clockedIn($user);

        Carbon::setTestNow(Carbon::now());
        $this->actingAs($user)->post('/break/start');

        Carbon::setTestNow(Carbon::now()->addMinutes(75));
        $this->actingAs($user)->post('/break/end');

        $attendance->refresh();

        $this->assertSame(75, $attendance->break_minutes);
        $this->assertSame(15, $attendance->breakOverrunMinutes($this->location->break_minutes));

        Carbon::setTestNow();
    }

    public function test_break_time_is_deducted_from_hours_worked(): void
    {
        $user = $this->staff();
        $start = Carbon::now()->subHours(8);
        $attendance = $this->clockedIn($user, $start);

        $attendance->forceFill([
            'break_started_at' => $start->copy()->addHours(4),
            'break_ended_at' => $start->copy()->addHours(4)->addMinutes(45),
            'break_minutes' => 45,
        ])->save();

        $this->actingAs($user)->post('/clock/out', [
            'latitude' => $this->location->latitude,
            'longitude' => $this->location->longitude,
            'accuracy' => 10,
        ]);

        // Eight hours on site, three quarters of an hour of it on break.
        $this->assertSame(8 * 60 - 45, $attendance->refresh()->worked_minutes);
    }

    public function test_clocking_out_closes_a_break_left_running(): void
    {
        $user = $this->staff();
        $start = Carbon::now()->subHours(5);
        $attendance = $this->clockedIn($user, $start);

        $attendance->forceFill(['break_started_at' => Carbon::now()->subMinutes(30)])->save();

        $this->actingAs($user)->post('/clock/out', [
            'latitude' => $this->location->latitude,
            'longitude' => $this->location->longitude,
            'accuracy' => 10,
        ]);

        $attendance->refresh();

        $this->assertNotNull($attendance->break_ended_at);
        $this->assertSame(30, $attendance->break_minutes);
        $this->assertSame(5 * 60 - 30, $attendance->worked_minutes);
    }

    public function test_super_admins_do_not_take_breaks(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post('/break/start')
            ->assertForbidden();
    }
}
