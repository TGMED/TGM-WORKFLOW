<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\Permission;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create(['workdays' => [1, 2, 3, 4, 5]]);
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * @param  array<int, Permission>  $permissions
     */
    private function staffWith(array $permissions = []): User
    {
        $role = Role::query()->create([
            'slug' => 'custom_'.Role::query()->count(),
            'name' => 'Custom',
            'is_system' => false,
        ]);

        $role->syncPermissions($permissions);

        return User::factory()->create([
            'role_id' => $role->id,
            'location_id' => $this->location->id,
        ]);
    }

    public function test_the_console_is_behind_its_own_permission(): void
    {
        $this->actingAs($this->staffWith())->get('/admin')->assertForbidden();
        $this->actingAs($this->staffWith([Permission::ViewAdminDashboard]))->get('/admin')->assertOk();
    }

    public function test_super_admins_reach_it(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/Dashboard')->etc());
    }

    public function test_the_headline_counts_the_company(): void
    {
        User::factory()->count(3)->create(['location_id' => $this->location->id]);
        User::factory()->onProbation()->create(['location_id' => $this->location->id]);
        User::factory()->create(['location_id' => null]);

        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('headline.active_staff', 5)
                ->where('headline.on_probation', 1)
                ->where('headline.unassigned', 1)
                ->where('headline.sites', 1)
                ->etc());
    }

    public function test_todays_attendance_shows_on_the_console(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        Attendance::query()->create([
            'user_id' => $staff->id,
            'location_id' => $this->location->id,
            'work_date' => Carbon::now()->toDateString(),
            'clocked_in_at' => Carbon::now(),
            'status' => AttendanceStatus::Late,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('headline.clocked_in_today', 1)
                ->where('headline.late_today', 1)
                ->etc());
    }

    public function test_leave_owed_is_headcount_times_the_allowance_less_what_is_taken(): void
    {
        $type = LeaveType::factory()->create(['days_per_year' => 10, 'days_per_year_manager' => null]);
        $staff = User::factory()->count(2)->create(['location_id' => $this->location->id]);

        LeaveRequest::factory()->create([
            'user_id' => $staff->first()->id,
            'leave_type_id' => $type->id,
            'days' => 4,
            'status' => RequestStatus::Approved,
            'start_date' => Carbon::now(),
            'end_date' => Carbon::now()->addDays(3),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('leave_liability', function ($rows) use ($type): bool {
                    $row = collect($rows)->firstWhere('id', $type->id);

                    // Two people at ten days, less the four already promised.
                    return $row['entitled'] === 20
                        && $row['taken'] === 4
                        && $row['outstanding'] === 16;
                })
                ->etc());
    }

    public function test_the_oldest_pending_request_is_surfaced(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        LeaveRequest::factory()->create([
            'user_id' => $staff->id,
            'leave_type_id' => LeaveType::query()->where('slug', 'annual')->firstOrFail()->id,
            'status' => RequestStatus::Pending,
            'days' => 2,
            'start_date' => Carbon::now()->addWeek(),
            'end_date' => Carbon::now()->addWeek()->addDay(),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('headline.pending_requests', 1)
                ->where('oldest_pending.0.staff', $staff->name)
                ->etc());
    }

    public function test_the_trend_covers_a_fortnight(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('attendance_trend', 14)->etc());
    }

    public function test_recent_changes_are_withheld_without_the_audit_permission(): void
    {
        $this->actingAs($this->staffWith([Permission::ViewAdminDashboard]))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('recent_activity', null)->etc());
    }

    public function test_recent_changes_show_for_somebody_who_may_read_the_trail(): void
    {
        $viewer = $this->staffWith([Permission::ViewAdminDashboard, Permission::ViewAuditTrail]);

        $this->actingAs($viewer)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('recent_activity', fn ($rows): bool => $rows !== null)
                ->etc());
    }

    public function test_every_role_is_listed_with_its_headcount(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('roles', fn ($rows): bool => collect($rows)
                    ->contains(fn (array $role): bool => $role['name'] === 'Super Admin'
                        && $role['holds_everything'] === true))
                ->etc());
    }
}
