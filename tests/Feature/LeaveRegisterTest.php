<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\RequestStatus;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every leave request in the company, read-only, for the people team.
 */
class LeaveRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private LeaveType $annual;

    private LeaveType $sick;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
        $this->annual = LeaveType::factory()->create(['name' => 'Annual leave']);
        $this->sick = LeaveType::factory()->create(['name' => 'Sick leave']);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create([
            'location_id' => $this->location->id,
            ...$attributes,
        ]);
    }

    /**
     * Somebody on the people team, who may read the register.
     */
    private function officer(): User
    {
        $role = Role::query()->create([
            'slug' => 'people_'.Role::query()->count(),
            'name' => 'People team',
            'is_system' => false,
        ]);

        $role->syncPermissions([Permission::ViewLeaveRegister]);

        return User::factory()->roles($role->slug)->create([
            'location_id' => $this->location->id,
        ]);
    }

    private function leaveFor(User $user, LeaveType $type, string $start, array $attributes = []): LeaveRequest
    {
        return LeaveRequest::factory()->create([
            'user_id' => $user->id,
            'leave_type_id' => $type->id,
            'start_date' => $start,
            'end_date' => $start,
            'days' => 1,
            ...$attributes,
        ]);
    }

    public function test_the_register_carries_everybodys_leave_not_just_your_own(): void
    {
        $one = $this->staff();
        $two = $this->staff();

        $this->leaveFor($one, $this->annual, '2026-03-04');
        $this->leaveFor($two, $this->sick, '2026-05-06');

        $this->actingAs($this->officer())
            ->get('/admin/leave?year=2026')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/LeaveRegister')
                ->has('rows.data', 2)
                ->where('summary.requests', 2)
                ->where('summary.people', 2));
    }

    public function test_it_filters_by_status_type_department_person_and_year(): void
    {
        $department = Department::factory()->create();

        $inside = $this->staff(['name' => 'Ada Lovelace', 'department_id' => $department->id]);
        $outside = $this->staff(['name' => 'Grace Hopper']);

        $this->leaveFor($inside, $this->annual, '2026-03-04', ['status' => RequestStatus::Approved]);
        $this->leaveFor($inside, $this->sick, '2026-04-04', ['status' => RequestStatus::Pending]);
        $this->leaveFor($outside, $this->annual, '2026-05-04', ['status' => RequestStatus::Approved]);
        $this->leaveFor($outside, $this->annual, '2024-05-04', ['status' => RequestStatus::Approved]);

        $officer = $this->officer();

        $this->actingAs($officer)
            ->get('/admin/leave?year=2026&status=approved')
            ->assertInertia(fn ($page) => $page->has('rows.data', 2));

        $this->actingAs($officer)
            ->get("/admin/leave?year=2026&type={$this->sick->id}")
            ->assertInertia(fn ($page) => $page->has('rows.data', 1));

        $this->actingAs($officer)
            ->get("/admin/leave?year=2026&department={$department->id}")
            ->assertInertia(fn ($page) => $page->has('rows.data', 2));

        $this->actingAs($officer)
            ->get('/admin/leave?year=2026&search=Grace')
            ->assertInertia(fn ($page) => $page->has('rows.data', 1));

        // The year is a filter like the rest: 2024 has the one request in it.
        $this->actingAs($officer)
            ->get('/admin/leave?year=2024')
            ->assertInertia(fn ($page) => $page->has('rows.data', 1));
    }

    public function test_the_tallies_answer_for_the_filters_not_for_the_page(): void
    {
        $person = $this->staff();

        // More than one page of requests, so a tally taken from the rows on
        // screen would come out short.
        for ($i = 1; $i <= 25; $i++) {
            $this->leaveFor(
                $person,
                $this->annual,
                sprintf('2026-01-%02d', $i),
                ['status' => RequestStatus::Approved],
            );
        }

        $this->actingAs($this->officer())
            ->get('/admin/leave?year=2026')
            ->assertInertia(fn ($page) => $page
                ->has('rows.data', 20)
                ->where('summary.requests', 25)
                ->where('summary.days', 25));
    }

    public function test_the_register_is_read_only(): void
    {
        $leave = $this->leaveFor($this->staff(), $this->annual, '2026-03-04');

        // Nothing writes through the register: deciding on leave is the
        // approvals inbox's job and goes through the chain. There is no route
        // here to take a decision, so there is nothing to reach.
        $officer = $this->officer();

        $this->actingAs($officer)->put("/admin/leave/{$leave->id}")->assertNotFound();
        $this->actingAs($officer)->delete("/admin/leave/{$leave->id}")->assertNotFound();
        $this->actingAs($officer)->post("/admin/leave/{$leave->id}")->assertNotFound();

        $this->assertSame(RequestStatus::Pending, $leave->refresh()->status);
    }

    public function test_it_downloads_as_a_spreadsheet_over_the_same_filters(): void
    {
        $one = $this->staff(['name' => 'Ada Lovelace']);
        $two = $this->staff(['name' => 'Grace Hopper']);

        $this->leaveFor($one, $this->annual, '2026-03-04');
        $this->leaveFor($two, $this->annual, '2026-03-05');

        $response = $this->actingAs($this->officer())
            ->get('/admin/leave/export?year=2026&search=Ada');

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Ada Lovelace', $csv);
        $this->assertStringNotContainsString('Grace Hopper', $csv);
    }

    public function test_the_register_is_behind_its_own_permission(): void
    {
        $this->actingAs($this->staff())->get('/admin/leave')->assertForbidden();

        // Holding staff management is not enough: the register carries the
        // reasons people gave for their time off.
        $role = Role::query()->create([
            'slug' => 'staff_admin',
            'name' => 'Staff admin',
            'is_system' => false,
        ]);

        $role->syncPermissions([Permission::ManageStaff]);

        $this->actingAs(User::factory()->roles($role->slug)->create([
            'location_id' => $this->location->id,
        ]))->get('/admin/leave')->assertForbidden();
    }
}
