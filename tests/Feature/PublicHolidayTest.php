<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Support\Workdays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Days the whole company is off: set by the people team, never counted as a
 * day anybody was expected in, and shown to staff where they already look.
 */
class PublicHolidayTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create([
            'timezone' => 'Africa/Lagos',
            'workdays' => [1, 2, 3, 4, 5],
        ]);
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    public function test_the_people_team_can_keep_the_calendar(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/holidays', ['name' => 'Independence Day', 'date' => '2026-10-01'])
            ->assertRedirect('/admin/holidays?year=2026');

        $holiday = PublicHoliday::query()->firstOrFail();
        $this->assertSame('2026-10-01', $holiday->date->toDateString());

        $this->actingAs($admin)
            ->put("/admin/holidays/{$holiday->id}", ['name' => 'Independence Day (observed)', 'date' => '2026-10-02'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-10-02', $holiday->refresh()->date->toDateString());

        $this->actingAs($admin)
            ->get('/admin/holidays?year=2026')
            ->assertInertia(fn ($page) => $page
                ->component('admin/Holidays')
                ->has('holidays', 1)
                ->where('holidays.0.name', 'Independence Day (observed)'));

        $this->actingAs($admin)->delete("/admin/holidays/{$holiday->id}");
        $this->assertSame(0, PublicHoliday::query()->count());
    }

    public function test_one_holiday_a_day(): void
    {
        PublicHoliday::factory()->create(['date' => '2026-12-25']);

        $this->actingAs($this->admin())
            ->post('/admin/holidays', ['name' => 'Christmas again', 'date' => '2026-12-25'])
            ->assertSessionHasErrors('date');
    }

    public function test_staff_cannot_touch_the_calendar(): void
    {
        $this->actingAs($this->staff())->get('/admin/holidays')->assertForbidden();
        $this->actingAs($this->staff())
            ->post('/admin/holidays', ['name' => 'Day off', 'date' => '2026-10-01'])
            ->assertForbidden();
    }

    public function test_a_holiday_is_never_a_working_day(): void
    {
        // Mon 28 Sep to Fri 2 Oct 2026, with the Thursday a holiday.
        $this->assertSame(4, Workdays::countBetween(
            Carbon::parse('2026-09-28'),
            Carbon::parse('2026-10-02'),
            [1, 2, 3, 4, 5],
            ['2026-10-01'],
        ));

        // A holiday on a day the site does not work anyway changes nothing.
        $this->assertSame(5, Workdays::countBetween(
            Carbon::parse('2026-09-28'),
            Carbon::parse('2026-10-04'),
            [1, 2, 3, 4, 5],
            ['2026-10-03'],
        ));
    }

    public function test_the_attendance_report_does_not_expect_anybody_in_on_a_holiday(): void
    {
        PublicHoliday::factory()->create(['name' => 'Independence Day', 'date' => '2026-10-01']);

        $staff = $this->staff();

        // Mon 28 Sep to Sun 4 Oct: five site workdays, one a holiday. Three
        // of the other four were worked.
        foreach (['2026-09-28', '2026-09-29', '2026-09-30'] as $date) {
            $at = Carbon::parse($date, 'Africa/Lagos');

            Attendance::query()->create([
                'user_id' => $staff->id,
                'location_id' => $this->location->id,
                'work_date' => $date,
                'clocked_in_at' => $at->copy()->setTime(9, 0)->utc(),
                'clocked_out_at' => $at->copy()->setTime(17, 0)->utc(),
                'status' => 'on_time',
                'late_minutes' => 0,
                'worked_minutes' => 480,
            ]);
        }

        $this->actingAs($this->admin())
            ->get('/admin/attendance?from=2026-09-28&to=2026-10-04')
            ->assertInertia(fn ($page) => $page
                ->where('rows.data.0.days_expected', 4)
                ->where('rows.data.0.days_absent', 1));
    }

    public function test_leave_over_a_holiday_does_not_spend_it(): void
    {
        $monday = Carbon::now()->addWeeks(2)->startOfWeek();
        PublicHoliday::factory()->create(['date' => $monday->copy()->addDays(2)->toDateString()]);

        $this->actingAs($this->staff())
            ->post('/leave', [
                'supervisor_id' => User::factory()->approver()->create()->id,
                'relief_officer_id' => $this->staff()->id,
                'leave_type_id' => LeaveType::query()->where('slug', 'annual')->firstOrFail()->id,
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->copy()->addDays(4)->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        // Monday to Friday is five working days, less the holiday.
        $this->assertSame(4, LeaveRequest::query()->firstOrFail()->days);
    }

    public function test_the_request_forms_are_told_the_holidays(): void
    {
        $date = Carbon::now()->addMonth()->toDateString();
        PublicHoliday::factory()->create(['name' => 'Workers Day', 'date' => $date]);

        $this->actingAs($this->staff())
            ->get('/leave')
            ->assertInertia(fn ($page) => $page->where("holidays.{$date}", 'Workers Day'));

        $this->actingAs($this->staff())
            ->get('/out-of-office')
            ->assertInertia(fn ($page) => $page->where("holidays.{$date}", 'Workers Day'));
    }

    public function test_the_next_holidays_are_on_the_rail_beside_every_page(): void
    {
        PublicHoliday::factory()->create([
            'name' => 'Democracy Day',
            'date' => Carbon::now()->addDays(3)->toDateString(),
        ]);

        $this->actingAs($this->staff())
            ->get('/leave')
            ->assertInertia(fn ($page) => $page
                ->where('noticeboard.events', fn ($events): bool => collect($events)->contains(
                    fn (array $event): bool => $event['kind'] === 'holiday' && $event['who'] === 'Democracy Day',
                )));
    }

    public function test_the_dashboard_names_a_holiday_rather_than_calling_it_a_workday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00', 'Africa/Lagos'));
        PublicHoliday::factory()->create(['name' => 'Independence Day', 'date' => '2026-10-01']);

        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('location.holiday', 'Independence Day')
                ->where('location.is_workday', false)
                ->where('trend.13.holiday', 'Independence Day')
                ->where('trend.13.is_workday', false));
    }
}
