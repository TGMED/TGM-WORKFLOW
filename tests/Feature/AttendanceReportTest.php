<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
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

    private function day(User $user, string $date, AttendanceStatus $status = AttendanceStatus::OnTime, int $lateMinutes = 0): Attendance
    {
        $at = Carbon::parse($date, 'Africa/Lagos');

        return Attendance::query()->create([
            'user_id' => $user->id,
            'location_id' => $this->location->id,
            'work_date' => $at->toDateString(),
            'clocked_in_at' => $at->copy()->setTime(9, 0)->utc(),
            'clocked_out_at' => $at->copy()->setTime(17, 0)->utc(),
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'worked_minutes' => 480,
        ]);
    }

    public function test_only_super_admins_can_open_the_report(): void
    {
        $this->actingAs(User::factory()->create(['location_id' => $this->location->id]))
            ->get('/admin/attendance')
            ->assertForbidden();
    }

    public function test_the_report_counts_only_days_inside_the_range(): void
    {
        $staff = User::factory()->create([
            'name' => 'Ada Nwosu',
            'location_id' => $this->location->id,
        ]);

        $this->day($staff, '2026-03-02');
        $this->day($staff, '2026-03-03', AttendanceStatus::Late, 25);
        $this->day($staff, '2026-04-06');

        $this->actingAs($this->admin())
            ->get('/admin/attendance?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/AttendanceReport')
                ->where('filters.from', '2026-03-01')
                ->where('filters.to', '2026-03-31')
                ->where('rows.data.0.name', 'Ada Nwosu')
                ->where('rows.data.0.days_present', 2)
                ->where('rows.data.0.days_late', 1)
                ->where('rows.data.0.late_minutes', 25)
                ->where('rows.data.0.punctuality', 50)
                ->where('summary.days_present', 2)
                ->where('summary.days_late', 1)
            );
    }

    public function test_grace_arrivals_are_counted_apart_from_late_ones(): void
    {
        $staff = User::factory()->create([
            'name' => 'Ada Nwosu',
            'location_id' => $this->location->id,
        ]);

        $this->day($staff, '2026-03-02');
        $this->day($staff, '2026-03-03', AttendanceStatus::Grace);
        $this->day($staff, '2026-03-04', AttendanceStatus::Grace);
        $this->day($staff, '2026-03-05', AttendanceStatus::Late, 25);

        $this->actingAs($this->admin())
            ->get('/admin/attendance?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.data.0.days_present', 4)
                ->where('rows.data.0.days_grace', 2)
                ->where('rows.data.0.days_late', 1)
                ->where('rows.data.0.late_minutes', 25)
                // Grace days count as punctual: only the one late day does not.
                ->where('rows.data.0.punctuality', 75)
                ->where('summary.days_grace', 2)
                ->where('summary.days_late', 1)
                ->where('summary.punctuality', 75)
            );
    }

    public function test_absences_are_measured_against_the_sites_working_week(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        // Mon 2 Mar to Fri 6 Mar is five workdays; two were worked.
        $this->day($staff, '2026-03-02');
        $this->day($staff, '2026-03-03');

        $this->actingAs($this->admin())
            ->get('/admin/attendance?from=2026-03-02&to=2026-03-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.data.0.days_expected', 5)
                ->where('rows.data.0.days_absent', 3)
            );
    }

    public function test_a_reversed_range_is_read_the_right_way_round(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/attendance?from=2026-03-31&to=2026-03-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.from', '2026-03-01')
                ->where('filters.to', '2026-03-31')
                ->where('range_days', 31)
            );
    }

    public function test_a_nonsense_range_falls_back_to_the_month_so_far(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-18 10:00:00'));

        $this->actingAs($this->admin())
            ->get('/admin/attendance?from=not-a-date&to=')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.from', '2026-03-01')
                ->where('filters.to', '2026-03-18')
            );

        Carbon::setTestNow();
    }

    public function test_the_report_can_be_narrowed_to_one_location(): void
    {
        $other = Location::factory()->create(['name' => 'TGM Abuja']);

        User::factory()->create(['name' => 'Ada Nwosu', 'location_id' => $this->location->id]);
        User::factory()->create(['name' => 'Bode Ajayi', 'location_id' => $other->id]);

        $this->actingAs($this->admin())
            ->get("/admin/attendance?location={$other->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.total', 1)
                ->where('rows.data.0.name', 'Bode Ajayi')
                ->where('summary.staff', 1)
            );
    }

    public function test_the_export_is_behind_the_same_permission_as_the_report(): void
    {
        $this->actingAs(User::factory()->create(['location_id' => $this->location->id]))
            ->get('/admin/attendance/export')
            ->assertForbidden();
    }

    public function test_the_export_covers_everyone_the_filters_match_not_just_the_page(): void
    {
        foreach (range(1, 20) as $n) {
            User::factory()->create([
                'name' => sprintf('Staffer %02d', $n),
                'location_id' => $this->location->id,
            ]);
        }

        $csv = $this->actingAs($this->admin())
            ->get('/admin/attendance/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        // The page shows fifteen; the file must hold all twenty, plus a header.
        $this->assertCount(21, array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString('Staffer 20', $csv);
    }

    public function test_the_export_totals_only_days_inside_the_range(): void
    {
        $staff = User::factory()->create([
            'name' => 'Ada Nwosu',
            'employee_id' => 'TGM-0042',
            'location_id' => $this->location->id,
        ]);

        $this->day($staff, '2026-03-02');
        $this->day($staff, '2026-03-03', AttendanceStatus::Late, 25);
        $this->day($staff, '2026-04-01');

        $csv = $this->actingAs($this->admin())
            ->get('/admin/attendance/export?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->streamedContent();

        // Excel needs the byte order mark; a parser does not.
        $rows = array_map(
            'str_getcsv',
            array_filter(explode("\n", trim(ltrim($csv, "\u{FEFF}")))),
        );

        $this->assertSame('Employee ID', $rows[0][0]);
        $this->assertSame('TGM-0042', $rows[1][0]);
        $this->assertSame('Ada Nwosu', $rows[1][1]);

        $header = array_flip($rows[0]);
        $this->assertSame('2', $rows[1][$header['Days present']]);
        $this->assertSame('1', $rows[1][$header['Days late']]);
        $this->assertSame('25', $rows[1][$header['Late minutes']]);
        $this->assertSame('16.0', $rows[1][$header['Hours worked']]);
        $this->assertSame('2026-03-03', $rows[1][$header['Last seen']]);
    }

    public function test_the_export_names_the_file_for_the_window_it_covers(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/attendance/export?from=2026-03-01&to=2026-03-31')
            ->assertDownload('attendance-2026-03-01-to-2026-03-31.csv');
    }

    public function test_super_admins_are_not_listed_as_staff(): void
    {
        $admin = $this->admin();

        User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($admin)
            ->get('/admin/attendance')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.total', 1));
    }
}
