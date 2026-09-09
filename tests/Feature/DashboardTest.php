<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create([
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'timezone' => 'Africa/Lagos',
            'work_starts_at' => '09:00:00',
            'grace_minutes' => 10,
        ]);
    }

    /**
     * Model date casts return CarbonImmutable, so the dashboard must be able to
     * build the arrival trend from real attendance rows.
     */
    public function test_the_dashboard_renders_with_attendance_history(): void
    {
        $user = User::factory()->create(['location_id' => $this->location->id]);

        foreach (range(1, 6) as $daysAgo) {
            $date = Carbon::now()->setTimezone('Africa/Lagos')->subDays($daysAgo);

            Attendance::query()->create([
                'user_id' => $user->id,
                'location_id' => $this->location->id,
                'work_date' => $date->toDateString(),
                'clocked_in_at' => $date->copy()->setTime(9, 20)->utc(),
                'clocked_out_at' => $date->copy()->setTime(17, 5)->utc(),
                'status' => AttendanceStatus::Late,
                'late_minutes' => 20,
                'worked_minutes' => 465,
            ]);
        }

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_the_dashboard_renders_for_a_brand_new_user(): void
    {
        $this->actingAs(User::factory()->create(['location_id' => $this->location->id]))
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_super_admins_get_the_company_overview(): void
    {
        $admin = User::factory()->superAdmin()->create(['location_id' => $this->location->id]);
        $staff = User::factory(2)->create(['location_id' => $this->location->id]);

        $today = Carbon::now()->setTimezone('Africa/Lagos');

        foreach ($staff as $person) {
            Attendance::query()->create([
                'user_id' => $person->id,
                'location_id' => $this->location->id,
                'work_date' => $today->toDateString(),
                'clocked_in_at' => $today->copy()->setTime(9, 30)->utc(),
                'status' => AttendanceStatus::Late,
                'late_minutes' => 30,
            ]);
        }

        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_the_attendance_history_page_renders(): void
    {
        $user = User::factory()->create(['location_id' => $this->location->id]);
        $date = Carbon::now()->setTimezone('Africa/Lagos');

        Attendance::query()->create([
            'user_id' => $user->id,
            'location_id' => $this->location->id,
            'work_date' => $date->toDateString(),
            'clocked_in_at' => $date->copy()->setTime(8, 55)->utc(),
            'status' => AttendanceStatus::OnTime,
            'late_minutes' => 0,
        ]);

        $this->actingAs($user)->get('/attendance')->assertOk();
        $this->actingAs($user)->get('/attendance?month=2026-01')->assertOk();
        // A malformed month must not blow up the page.
        $this->actingAs($user)->get('/attendance?month=garbage')->assertOk();
    }

    public function test_the_admin_clock_attempt_log_renders(): void
    {
        $admin = User::factory()->superAdmin()->create(['location_id' => $this->location->id]);

        $this->actingAs($admin)->get('/admin/clock-attempts')->assertOk();
        $this->actingAs($admin)->get('/admin/clock-attempts?result=rejected&range=30d')->assertOk();
        $this->actingAs($admin)->get('/admin/locations')->assertOk();
    }

    public function test_the_dashboard_shows_what_leave_is_left_and_what_is_next(): void
    {
        $user = User::factory()->create(['location_id' => $this->location->id]);
        $annual = LeaveType::query()->where('slug', 'annual')->firstOrFail();

        $start = Carbon::now()->addWeek()->startOfWeek();

        LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type_id' => $annual->id,
            'start_date' => $start,
            'end_date' => $start->copy()->addDays(2),
            'days' => 3,
            'status' => RequestStatus::Approved,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('leave.next.type', 'Annual leave')
                ->where('leave.next.days', 3)
                ->where('leave.next.started', false)
                ->where('leave.pending', 0)
                ->where('leave.balances.0.remaining', $annual->days_per_year - 3));
    }

    public function test_the_company_console_counts_who_is_on_leave_today(): void
    {
        $onLeave = User::factory()->create(['location_id' => $this->location->id]);

        LeaveRequest::factory()->create([
            'user_id' => $onLeave->id,
            'start_date' => Carbon::now()->subDay(),
            'end_date' => Carbon::now()->addDay(),
            'days' => 3,
            'status' => RequestStatus::Approved,
        ]);

        // Still pending, so it does not count as time off yet.
        LeaveRequest::factory()->create([
            'user_id' => User::factory()->create(['location_id' => $this->location->id])->id,
            'start_date' => Carbon::now(),
            'end_date' => Carbon::now(),
            'days' => 1,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('metrics.headline.on_leave_today', 1));
    }
}
