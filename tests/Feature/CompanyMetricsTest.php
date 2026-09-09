<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\EmploymentStatus;
use App\Enums\Permission;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Metrics\CompanyMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The company console, and a head of department's view of their own people.
 */
class CompanyMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function staff(?Department $department = null, ?Team $team = null): User
    {
        return User::factory()
            ->inDepartment($department?->id, $team?->id)
            ->create(['location_id' => $this->location->id]);
    }

    private function attended(User $user, Carbon $date, bool $late = false, int $minutes = 0): Attendance
    {
        return Attendance::query()->create([
            'user_id' => $user->id,
            'location_id' => $this->location->id,
            'work_date' => $date->toDateString(),
            'clocked_in_at' => $date->copy()->setTime(9, $late ? 30 : 0)->utc(),
            'status' => $late ? AttendanceStatus::Late : AttendanceStatus::OnTime,
            'late_minutes' => $late ? $minutes : 0,
            'worked_minutes' => 465,
        ]);
    }

    // The console.

    public function test_the_console_counts_who_is_in_and_who_is_late_today(): void
    {
        $today = Carbon::now()->startOfDay();

        $onTime = $this->staff();
        $late = $this->staff();
        $this->staff();

        $this->attended($onTime, $today);
        $this->attended($late, $today, late: true, minutes: 25);

        $metrics = app(CompanyMetrics::class)->all($this->admin());

        $this->assertSame(2, $metrics['headline']['clocked_in_today']);
        $this->assertSame(1, $metrics['headline']['late_today']);
        $this->assertSame(3, $metrics['headline']['active_staff']);
    }

    public function test_the_console_counts_people_with_no_department(): void
    {
        $department = Department::factory()->create();

        $this->staff($department);
        $this->staff();
        $this->staff();

        $metrics = app(CompanyMetrics::class)->all($this->admin());

        $this->assertSame(2, $metrics['headline']['unassigned_department']);
    }

    public function test_the_departments_table_measures_each_department_on_its_own(): void
    {
        $today = Carbon::now()->startOfDay();

        $operations = Department::factory()->create(['name' => 'Operations']);
        $finance = Department::factory()->create(['name' => 'Finance']);

        $head = $this->staff($operations);
        $operations->forceFill(['head_user_id' => $head->id])->save();

        $punctual = $this->staff($operations);
        $tardy = $this->staff($finance);

        $this->attended($head, $today);
        $this->attended($punctual, $today);
        $this->attended($tardy, $today, late: true, minutes: 40);

        $metrics = app(CompanyMetrics::class)->all($this->admin());

        $rows = collect($metrics['departments'])->keyBy('name');

        $this->assertSame(2, $rows['Operations']['headcount']);
        $this->assertSame(100, $rows['Operations']['turnout']);
        $this->assertSame(100, $rows['Operations']['punctuality']);
        $this->assertSame($head->name, $rows['Operations']['head']);

        $this->assertSame(1, $rows['Finance']['headcount']);
        $this->assertSame(0, $rows['Finance']['punctuality']);
        $this->assertSame(40, $rows['Finance']['late_minutes']);
        $this->assertNull($rows['Finance']['head']);
    }

    public function test_punctuality_is_null_rather_than_a_hundred_per_cent_when_nobody_has_worked(): void
    {
        $this->staff();

        $metrics = app(CompanyMetrics::class)->all($this->admin());

        $this->assertNull($metrics['headline']['punctuality_this_month']);
    }

    public function test_probation_names_who_is_overdue_and_who_is_due_soon(): void
    {
        config(['hr.probation_months' => 6]);

        $overdue = User::factory()->create([
            'location_id' => $this->location->id,
            'employment_status' => EmploymentStatus::Probation,
            'hired_at' => Carbon::now()->subMonths(9),
        ]);

        $soon = User::factory()->create([
            'location_id' => $this->location->id,
            'employment_status' => EmploymentStatus::Probation,
            'hired_at' => Carbon::now()->subMonths(5),
        ]);

        // Confirmation is a long way off, so not worth a line on the console.
        User::factory()->create([
            'location_id' => $this->location->id,
            'employment_status' => EmploymentStatus::Probation,
            'hired_at' => Carbon::now()->subDays(3),
        ]);

        $metrics = app(CompanyMetrics::class)->all($this->admin());

        $this->assertSame([$overdue->id], array_column($metrics['probation']['overdue'], 'id'));
        $this->assertSame([$soon->id], array_column($metrics['probation']['due_soon'], 'id'));
        $this->assertSame(3, $metrics['probation']['total_on_probation']);
    }

    // Panels behind a permission.

    public function test_confidential_panels_are_absent_without_the_permission(): void
    {
        $role = Role::query()->create(['slug' => 'console_only', 'name' => 'Console', 'is_system' => false]);
        $role->syncPermissions([Permission::ViewAdminDashboard]);

        $viewer = User::factory()->roles($role->slug)->create(['location_id' => $this->location->id]);

        $metrics = app(CompanyMetrics::class)->all($viewer);

        $this->assertNull($metrics['payroll']);
        $this->assertNull($metrics['incidents']);
        $this->assertNull($metrics['access']);
    }

    public function test_a_super_admin_sees_the_confidential_panels(): void
    {
        $metrics = app(CompanyMetrics::class)->all($this->admin());

        $this->assertNotNull($metrics['payroll']);
        $this->assertNotNull($metrics['incidents']);
        $this->assertNotNull($metrics['access']);
    }

    /**
     * Thirteen panels is a lot of round trips. They share their reads, and a
     * ceiling here is what stops a fourteenth panel quietly turning the page
     * into an N+1: the count must not grow with the number of people.
     */
    public function test_the_console_does_not_cost_more_as_the_company_grows(): void
    {
        $department = Department::factory()->create();
        $admin = $this->admin();

        foreach (range(1, 3) as $ignored) {
            $this->attended($this->staff($department), Carbon::now()->startOfDay());
        }

        $small = $this->queriesFor($admin);

        foreach (range(1, 25) as $ignored) {
            $this->attended($this->staff($department), Carbon::now()->startOfDay());
        }

        $large = $this->queriesFor($admin);

        // The count must not grow with headcount. Equality is not quite the
        // right test — an eager load Laravel skips when there is nothing to
        // load makes the small case cheaper by a query or two — but growth in
        // the other direction is always an N+1.
        $this->assertLessThanOrEqual(
            $small,
            $large,
            "The console ran {$large} queries for 28 people and {$small} for 3. Something in it is asking per person.",
        );

        $this->assertLessThan(
            60,
            $large,
            "The console ran {$large} queries. Panels are meant to share their reads.",
        );
    }

    private function queriesFor(User $viewer): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(CompanyMetrics::class)->all($viewer);

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    // What a head of department sees.

    public function test_a_head_of_department_sees_their_own_people_on_their_dashboard(): void
    {
        $department = Department::factory()->create(['name' => 'Operations']);

        $head = User::factory()
            ->inDepartment($department)
            ->roles(Role::STAFF, Role::HEAD_OF_DEPARTMENT)
            ->create(['location_id' => $this->location->id]);

        $department->forceFill(['head_user_id' => $head->id])->save();

        $member = $this->staff($department);
        $outsider = $this->staff(Department::factory()->create());

        $this->attended($member, Carbon::now()->startOfDay(), late: true, minutes: 15);

        $this->actingAs($head)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('group.label', 'Operations')
                ->where('group.kind', 'department')
                ->where('group.headcount', 1)
                ->where('group.headline.late_today', 1)
                ->where('group.members.0.id', $member->id));

        $this->assertNotContains(
            $outsider->id,
            $head->fresh()->managedUserIds(),
        );
    }

    public function test_a_team_lead_sees_their_team_and_not_the_whole_department(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $department->id, 'name' => 'Night shift']);

        $lead = User::factory()
            ->inDepartment($department, $team)
            ->roles(Role::STAFF, Role::TEAM_LEAD)
            ->create(['location_id' => $this->location->id]);

        $team->forceFill(['lead_user_id' => $lead->id])->save();

        $onTeam = $this->staff($department, $team);
        $inDepartmentOnly = $this->staff($department);

        $managed = $lead->fresh()->managedUserIds();

        $this->assertContains($onTeam->id, $managed);
        $this->assertNotContains($inDepartmentOnly->id, $managed);
        $this->assertNotContains($lead->id, $managed);

        $this->actingAs($lead)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('group.kind', 'team')
                ->where('group.label', 'Night shift')
                ->where('group.headcount', 1));
    }

    // What a head of department may NOT see.

    public function test_a_head_of_department_gets_no_company_console(): void
    {
        $department = Department::factory()->create();

        $head = User::factory()
            ->inDepartment($department)
            ->roles(Role::STAFF, Role::HEAD_OF_DEPARTMENT)
            ->create(['location_id' => $this->location->id]);

        $department->forceFill(['head_user_id' => $head->id])->save();

        $this->staff($department);

        $this->actingAs($head)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Their own department, yes.
                ->has('group')
                // The whole company, no.
                ->where('metrics', null));
    }

    public function test_a_head_of_department_cannot_reach_the_company_wide_pages(): void
    {
        $head = User::factory()
            ->roles(Role::STAFF, Role::HEAD_OF_DEPARTMENT)
            ->create(['location_id' => $this->location->id]);

        $this->actingAs($head)->get('/admin')->assertForbidden();
        $this->actingAs($head)->get('/admin/attendance')->assertForbidden();
        $this->actingAs($head)->get('/admin/staff')->assertForbidden();
        $this->actingAs($head)->get('/admin/departments')->assertForbidden();
    }

    public function test_the_head_of_department_role_carries_no_company_wide_permission(): void
    {
        $role = Role::findBySlug(Role::HEAD_OF_DEPARTMENT);

        $this->assertFalse($role->hasPermission(Permission::ViewAdminDashboard));
        $this->assertFalse($role->hasPermission(Permission::ViewAttendanceReport));
        $this->assertFalse($role->hasPermission(Permission::ManageStaff));
        // What they do carry: deciding on their own people's requests.
        $this->assertTrue($role->hasPermission(Permission::ApproveRequests));
    }

    public function test_a_head_does_not_inherit_the_requests_of_people_outside_their_department(): void
    {
        $department = Department::factory()->create();

        $head = User::factory()
            ->inDepartment($department)
            ->roles(Role::STAFF, Role::HEAD_OF_DEPARTMENT)
            ->create(['location_id' => $this->location->id]);

        $department->forceFill(['head_user_id' => $head->id])->save();

        // Somebody in no department at all, so their request carries no
        // reporting line and falls through to the wider approver pool.
        $outsider = $this->staff();

        $leave = LeaveRequest::factory()->create([
            'user_id' => $outsider->id,
            'status' => RequestStatus::Pending,
            'supervisor_id' => null,
            'relief_officer_id' => null,
        ]);

        $this->assertFalse($leave->awaitsDecisionFrom($head->fresh()));

        // A plain approver still picks it up, as they always did.
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);

        $this->assertTrue($leave->fresh()->awaitsDecisionFrom($approver));
    }

    public function test_a_head_who_is_also_an_approver_keeps_company_wide_rights(): void
    {
        $department = Department::factory()->create();

        $both = User::factory()
            ->inDepartment($department)
            ->roles(Role::APPROVER, Role::HEAD_OF_DEPARTMENT)
            ->create(['location_id' => $this->location->id]);

        $department->forceFill(['head_user_id' => $both->id])->save();

        $outsider = $this->staff();

        $leave = LeaveRequest::factory()->create([
            'user_id' => $outsider->id,
            'status' => RequestStatus::Pending,
            'supervisor_id' => null,
            'relief_officer_id' => null,
        ]);

        // The right comes from being an approver, not from heading anything,
        // so it is not narrowed.
        $this->assertTrue($both->approvesCompanyWide());
        $this->assertTrue($leave->awaitsDecisionFrom($both->fresh()));
    }

    public function test_the_on_behalf_form_offers_a_head_only_their_own_people(): void
    {
        $department = Department::factory()->create();

        $head = User::factory()
            ->inDepartment($department)
            ->roles(Role::STAFF, Role::HEAD_OF_DEPARTMENT)
            ->create(['location_id' => $this->location->id]);

        $department->forceFill(['head_user_id' => $head->id])->save();

        $mine = $this->staff($department);
        $theirs = $this->staff(Department::factory()->create());

        $response = $this->actingAs($head)->get('/approvals')->assertOk();

        $offered = collect($response->viewData('page')['props']['raise']['staff'])->pluck('value');

        $this->assertTrue($offered->contains($mine->id));
        $this->assertFalse($offered->contains($theirs->id));
    }

    public function test_somebody_who_runs_nothing_gets_no_group(): void
    {
        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('group', null));
    }

    public function test_a_groups_open_requests_are_counted(): void
    {
        $department = Department::factory()->create();

        $head = User::factory()
            ->inDepartment($department)
            ->roles(Role::STAFF, Role::HEAD_OF_DEPARTMENT)
            ->create(['location_id' => $this->location->id]);

        $department->forceFill(['head_user_id' => $head->id])->save();

        $member = $this->staff($department);

        LeaveRequest::factory()->create([
            'user_id' => $member->id,
            'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($head)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('group.headline.open_requests', 1));
    }
}
