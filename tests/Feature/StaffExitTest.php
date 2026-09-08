<?php

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\AttendanceStatus;
use App\Enums\ExitReason;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\PushToken;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\StaffExit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Walking somebody out, and what stops counting once they have gone.
 */
class StaffExitTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->location = Location::factory()->create(['workdays' => [1, 2, 3, 4, 5]]);
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::idFor(Role::SUPER_ADMIN),
            'location_id' => $this->location->id,
        ]);
    }

    private function exit(User $staff, ?Carbon $lastDay = null): void
    {
        app(StaffExit::class)->record(
            $staff,
            ExitReason::Resignation,
            $lastDay ?? Carbon::now(),
            null,
        );
    }

    public function test_an_exit_withdraws_requests_nobody_can_decide_any_more(): void
    {
        $staff = $this->staff();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $leave = LeaveRequest::factory()->create([
            'user_id' => $staff->id,
            'status' => RequestStatus::Pending,
        ]);

        $lateness = LatenessRequest::factory()->create([
            'user_id' => $staff->id,
            'status' => RequestStatus::Pending,
        ]);

        $settled = LeaveRequest::factory()->create([
            'user_id' => $staff->id,
            'status' => RequestStatus::Approved,
            'start_date' => $monday,
            'end_date' => $monday->copy()->addDay(),
        ]);

        $this->exit($staff);

        $this->assertSame(RequestStatus::Cancelled, $leave->refresh()->status);
        $this->assertSame(RequestStatus::Cancelled, $lateness->refresh()->status);

        // A decision already taken is history, not an open loop.
        $this->assertSame(RequestStatus::Approved, $settled->refresh()->status);
    }

    public function test_an_exit_reports_the_cover_it_leaves_behind(): void
    {
        $staff = $this->staff();
        $colleague = $this->staff();
        $monday = Carbon::now()->addWeek()->startOfWeek();

        $covered = LeaveRequest::factory()->create([
            'user_id' => $colleague->id,
            'relief_officer_id' => $staff->id,
            'status' => RequestStatus::Approved,
            'start_date' => $monday,
            'end_date' => $monday->copy()->addDays(2),
        ]);

        $outcome = app(StaffExit::class)->record(
            $staff,
            ExitReason::Resignation,
            Carbon::now(),
            null,
        );

        $this->assertSame(1, $outcome->coverToReassign);
        $this->assertStringContainsString('relief officer', (string) $outcome->summary());

        // The colleague's own leave is untouched: it is theirs, not the
        // leaver's, and somebody still has to decide what happens to it.
        $this->assertSame(RequestStatus::Approved, $covered->refresh()->status);
    }

    public function test_an_exit_clears_the_devices_and_the_open_sessions(): void
    {
        $staff = $this->staff();

        PushToken::query()->create([
            'user_id' => $staff->id,
            'token' => 'a-browser-push-token',
            'user_agent' => 'Test browser',
        ]);

        $this->exit($staff);

        $this->assertSame(0, PushToken::query()->where('user_id', $staff->id)->count());
    }

    public function test_a_leaver_stops_counting_towards_company_attendance(): void
    {
        $staying = $this->staff();
        $leaving = $this->staff();
        $today = Carbon::now()->startOfDay();

        foreach ([$staying, $leaving] as $person) {
            Attendance::query()->create([
                'user_id' => $person->id,
                'location_id' => $this->location->id,
                'work_date' => $today,
                'clocked_in_at' => $today->copy()->addHours(9),
                'status' => AttendanceStatus::Late,
                'late_minutes' => 30,
            ]);
        }

        $before = $this->actingAs($this->admin())->get('/admin')->viewData('page')['props']['headline'];
        $this->assertSame(2, $before['late_today']);
        $this->assertSame(2, $before['clocked_in_today']);

        $this->exit($leaving);

        $after = $this->actingAs($this->admin())->get('/admin')->viewData('page')['props']['headline'];
        $this->assertSame(1, $after['late_today']);
        $this->assertSame(1, $after['clocked_in_today']);
    }

    public function test_a_leavers_days_stay_on_their_own_record(): void
    {
        $leaving = $this->staff();
        $today = Carbon::now()->startOfDay();

        Attendance::query()->create([
            'user_id' => $leaving->id,
            'location_id' => $this->location->id,
            'work_date' => $today,
            'clocked_in_at' => $today->copy()->addHours(9),
            'status' => AttendanceStatus::Late,
            'late_minutes' => 30,
        ]);

        $this->exit($leaving);

        // Dropping out of the company figures is not the same as being
        // erased: their own profile still answers for the days they worked.
        $props = $this->actingAs($this->admin())
            ->get("/admin/staff/{$leaving->id}")
            ->viewData('page')['props'];

        $this->assertSame(1, $props['stats']['days_present']);
        $this->assertSame(1, $props['stats']['days_late']);
        $this->assertTrue($props['staff']['has_exited']);
        $this->assertSame('resignation', $props['staff']['exit_reason']);
    }

    public function test_an_approved_explanation_stops_the_day_counting_as_late(): void
    {
        $staff = $this->staff();
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);
        $today = Carbon::now()->startOfDay();

        $attendance = Attendance::query()->create([
            'user_id' => $staff->id,
            'location_id' => $this->location->id,
            'work_date' => $today,
            'clocked_in_at' => $today->copy()->addHours(9),
            'status' => AttendanceStatus::Late,
            'late_minutes' => 45,
        ]);

        $request = LatenessRequest::factory()->create([
            'user_id' => $staff->id,
            'attendance_id' => $attendance->id,
            'work_date' => $today,
            'minutes_late' => 45,
            'status' => RequestStatus::Pending,
            'approvals_required' => 1,
        ]);

        $before = $this->actingAs($this->admin())->get('/admin')->viewData('page')['props']['headline'];
        $this->assertSame(1, $before['late_today']);

        app(ApprovalService::class)->decide($request, $approver, ApprovalDecision::Approved);

        $this->assertSame(RequestStatus::Approved, $request->refresh()->status);

        $attendance->refresh();
        $this->assertTrue($attendance->isExcused());
        $this->assertFalse($attendance->countsAsLate());

        // The day itself is not rewritten: it still says what happened.
        $this->assertSame(AttendanceStatus::Late, $attendance->status);
        $this->assertSame(45, $attendance->late_minutes);

        $after = $this->actingAs($this->admin())->get('/admin')->viewData('page')['props']['headline'];
        $this->assertSame(0, $after['late_today']);
    }

    public function test_an_excused_day_drops_out_of_the_persons_own_figures(): void
    {
        $staff = $this->staff();
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);
        $today = Carbon::now()->startOfDay();

        $attendance = Attendance::query()->create([
            'user_id' => $staff->id,
            'location_id' => $this->location->id,
            'work_date' => $today,
            'clocked_in_at' => $today->copy()->addHours(9),
            'status' => AttendanceStatus::Late,
            'late_minutes' => 45,
            'worked_minutes' => 400,
        ]);

        $request = LatenessRequest::factory()->create([
            'user_id' => $staff->id,
            'attendance_id' => $attendance->id,
            'work_date' => $today,
            'minutes_late' => 45,
            'status' => RequestStatus::Pending,
            'approvals_required' => 1,
        ]);

        app(ApprovalService::class)->decide($request, $approver, ApprovalDecision::Approved);

        $summary = $this->actingAs($staff)->get('/attendance')->viewData('page')['props']['summary'];

        $this->assertSame(1, $summary['days_present']);
        $this->assertSame(0, $summary['days_late']);
        $this->assertSame(1, $summary['days_excused']);
        $this->assertSame(0, $summary['late_minutes']);
    }

    public function test_a_rejected_explanation_leaves_the_lateness_standing(): void
    {
        $staff = $this->staff();
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);
        $today = Carbon::now()->startOfDay();

        $attendance = Attendance::query()->create([
            'user_id' => $staff->id,
            'location_id' => $this->location->id,
            'work_date' => $today,
            'clocked_in_at' => $today->copy()->addHours(9),
            'status' => AttendanceStatus::Late,
            'late_minutes' => 45,
        ]);

        $request = LatenessRequest::factory()->create([
            'user_id' => $staff->id,
            'attendance_id' => $attendance->id,
            'work_date' => $today,
            'status' => RequestStatus::Pending,
            'approvals_required' => 1,
        ]);

        app(ApprovalService::class)->decide($request, $approver, ApprovalDecision::Rejected);

        $this->assertFalse($attendance->refresh()->isExcused());
        $this->assertTrue($attendance->countsAsLate());
    }

    public function test_the_attendance_report_leaves_out_people_who_have_gone(): void
    {
        $staying = $this->staff();
        $leaving = $this->staff();

        $this->exit($leaving);

        $props = $this->actingAs($this->admin())
            ->get('/admin/attendance')
            ->viewData('page')['props'];

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $props['rows']['data'];
        $names = array_column($rows, 'name');

        $this->assertContains($staying->name, $names);
        $this->assertNotContains($leaving->name, $names);

        // Asked for by name, they are still there to be looked at.
        $withLeavers = $this->actingAs($this->admin())
            ->get('/admin/attendance?status=all')
            ->viewData('page')['props'];

        $this->assertContains($leaving->name, array_column($withLeavers['rows']['data'], 'name'));
    }
}
