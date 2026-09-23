<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AttendanceStatus;
use App\Enums\Permission;
use App\Enums\RequestStatus;
use App\Enums\RequisitionStatus;
use App\Models\Asset;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\PerformanceReview;
use App\Models\Requisition;
use App\Models\Role;
use App\Models\StaffAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Company-wide totals, on the page and as CSV.
 */
class HrReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 12:00:00');
        $this->location = Location::factory()->create();
    }

    private function viewer(): User
    {
        $role = Role::query()->create(['slug' => 'hr_reports', 'name' => 'HR reports', 'is_system' => false]);
        $role->syncPermissions([Permission::ViewHrReports]);

        return User::factory()->roles($role->slug)->create(['location_id' => $this->location->id]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function section(array $sections, string $key): array
    {
        return collect($sections)->firstWhere('key', $key);
    }

    public function test_every_section_adds_up_by_department(): void
    {
        $ops = Department::factory()->create(['name' => 'Operations']);
        $sales = Department::factory()->create(['name' => 'Sales']);

        $a = User::factory()->create(['location_id' => $this->location->id, 'department_id' => $ops->id, 'hired_at' => '2026-03-01']);
        $b = User::factory()->create(['location_id' => $this->location->id, 'department_id' => $sales->id, 'hired_at' => '2020-01-01']);

        Attendance::query()->create([
            'user_id' => $a->id, 'location_id' => $this->location->id, 'work_date' => '2026-09-01',
            'clocked_in_at' => '2026-09-01 09:30:00', 'status' => AttendanceStatus::Late, 'late_minutes' => 30, 'worked_minutes' => 450,
        ]);
        LeaveRequest::factory()->create(['user_id' => $b->id, 'start_date' => '2026-08-03', 'end_date' => '2026-08-05', 'days' => 3, 'status' => RequestStatus::Approved]);
        PerformanceReview::factory()->create(['subject_user_id' => $a->id, 'reviewer_id' => $b->id, 'rating' => 4]);
        PerformanceReview::factory()->private()->create(['subject_user_id' => $a->id, 'reviewer_id' => $b->id, 'rating' => 2]);
        StaffAction::factory()->query()->create(['subject_user_id' => $b->id]);
        Asset::factory()->create(['status' => AssetStatus::Assigned, 'purchase_cost' => 500000]);
        Requisition::factory()->create(['requester_id' => $a->id, 'department_id' => $ops->id, 'amount' => 20000, 'status' => RequisitionStatus::Paid]);

        $this->actingAs($this->viewer())
            ->get('/admin/hr-reports?from=2026-01-01&to=2026-09-30')
            ->assertInertia(function (Assert $page): void {
                $page->component('admin/HrReports')->has('sections', 7);

                $sections = $page->toArray()['props']['sections'];

                $headcount = $this->section($sections, 'headcount')['rows'];
                $this->assertSame(['Operations', 1, 0, 1, 0], array_slice($headcount[0], 0, 5));

                $attendance = $this->section($sections, 'attendance')['rows'];
                $this->assertSame(['Operations', 1, 1, 30, 7.5], $attendance[0]);

                $leave = $this->section($sections, 'leave')['rows'];
                $this->assertSame([1, 1, 0, 3], array_slice($leave[0], 1));

                $reviews = $this->section($sections, 'reviews')['rows'];
                $this->assertSame(['Operations', 2, 1, 1, 3], $reviews[0]);

                $conduct = $this->section($sections, 'conduct')['rows'];
                $this->assertSame(['Sales', 1, 1, 0, 0], $conduct[0]);

                $assets = $this->section($sections, 'assets')['rows'];
                $this->assertSame([1, 0, 1, 0, 0, '500000.00'], array_slice($assets[0], 1));

                $requisitions = $this->section($sections, 'requisitions')['rows'];
                $this->assertSame(['Operations', 1, '20000.00', '20000.00', '0.00', 1], $requisitions[0]);
            });
    }

    public function test_a_section_downloads_as_csv(): void
    {
        Department::factory()->create(['name' => 'Operations']);
        User::factory()->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->viewer())->get('/admin/hr-reports/headcount/export?from=2026-01-01&to=2026-09-30');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Department,"In post"', $response->streamedContent());
    }

    public function test_an_unknown_section_is_not_found(): void
    {
        $this->actingAs($this->viewer())->get('/admin/hr-reports/salaries/export')->assertNotFound();
    }

    public function test_the_reports_need_their_own_permission(): void
    {
        $this->actingAs(User::factory()->create(['location_id' => $this->location->id]))
            ->get('/admin/hr-reports')
            ->assertForbidden();
    }
}
