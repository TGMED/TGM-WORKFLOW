<?php

namespace Tests\Feature;

use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * An approver filing leave or lateness for a member of staff.
 */
class OnBehalfRequestTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create(['workdays' => [1, 2, 3, 4, 5]]);
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    private function approver(): User
    {
        return User::factory()->approver()->create(['location_id' => $this->location->id]);
    }

    private function annual(): LeaveType
    {
        return LeaveType::query()->where('slug', 'annual')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function leavePayload(User $staff, User $filer): array
    {
        $monday = Carbon::now()->addWeek()->startOfWeek();

        return [
            'staff_id' => $staff->id,
            'leave_type_id' => $this->annual()->id,
            // Somebody other than the filer has to rule on it.
            'supervisor_id' => User::factory()->approver()->create()->id,
            'relief_officer_id' => $this->staff()->id,
            'start_date' => $monday->toDateString(),
            'end_date' => $monday->copy()->addDays(2)->toDateString(),
            'reason' => 'Signed off by the clinic for three days.',
        ];
    }

    public function test_an_approver_can_raise_leave_for_a_member_of_staff(): void
    {
        $staff = $this->staff();
        $approver = $this->approver();

        $this->actingAs($approver)
            ->post('/approvals/on-behalf/leave', $this->leavePayload($staff, $approver))
            ->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();

        // The request belongs to the member of staff; only the filing is the
        // approver's.
        $this->assertSame($staff->id, $leave->user_id);
        $this->assertSame($approver->id, $leave->raised_by_id);
        $this->assertSame(3, $leave->days);
    }

    public function test_the_days_are_counted_against_the_staff_members_own_site(): void
    {
        // A site working Monday to Wednesday only, so the same three-day range
        // is measured against their week rather than the approver's.
        $site = Location::factory()->create(['workdays' => [1, 2, 3]]);
        $staff = User::factory()->create(['location_id' => $site->id]);
        $approver = $this->approver();

        $monday = Carbon::now()->addWeek()->startOfWeek();

        $this->actingAs($approver)
            ->post('/approvals/on-behalf/leave', [
                ...$this->leavePayload($staff, $approver),
                'start_date' => $monday->toDateString(),
                'end_date' => $monday->copy()->addDays(4)->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, LeaveRequest::query()->firstOrFail()->days);
    }

    public function test_an_approver_cannot_name_themselves_as_the_approver(): void
    {
        $staff = $this->staff();
        $approver = $this->approver();

        $this->actingAs($approver)
            ->post('/approvals/on-behalf/leave', [
                ...$this->leavePayload($staff, $approver),
                'supervisor_id' => $approver->id,
            ])
            ->assertSessionHasErrors('supervisor_id');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_an_approver_cannot_file_for_themselves_here(): void
    {
        $approver = $this->approver();

        $this->actingAs($approver)
            ->post('/approvals/on-behalf/leave', [
                ...$this->leavePayload($approver, $approver),
                'staff_id' => $approver->id,
            ])
            ->assertSessionHasErrors('staff_id');
    }

    public function test_staff_cannot_file_for_anyone_else(): void
    {
        $staff = $this->staff();
        $colleague = $this->staff();

        $this->actingAs($staff)
            ->post('/approvals/on-behalf/leave', $this->leavePayload($colleague, $staff))
            ->assertForbidden();
    }

    public function test_an_approver_can_file_lateness_for_a_member_of_staff(): void
    {
        $staff = $this->staff();
        $approver = $this->approver();

        $this->actingAs($approver)
            ->post('/approvals/on-behalf/lateness', [
                'staff_id' => $staff->id,
                'work_date' => Carbon::now()->subDays(3)->toDateString(),
                'reason' => 'Held up at the clinic with a sick child that morning.',
            ])
            ->assertSessionHasNoErrors();

        $late = LatenessRequest::query()->firstOrFail();

        $this->assertSame($staff->id, $late->user_id);
        $this->assertSame($approver->id, $late->raised_by_id);
    }

    public function test_lateness_filed_for_someone_reaches_back_only_a_fortnight(): void
    {
        $staff = $this->staff();

        $this->actingAs($this->approver())
            ->post('/approvals/on-behalf/lateness', [
                'staff_id' => $staff->id,
                'work_date' => Carbon::now()->subDays(20)->toDateString(),
                'reason' => 'Something that happened three weeks ago now.',
            ])
            ->assertSessionHasErrors('work_date');
    }

    public function test_a_day_already_explained_is_not_explained_twice(): void
    {
        $staff = $this->staff();
        $day = Carbon::now()->subDays(2);

        LatenessRequest::factory()->create([
            'user_id' => $staff->id,
            'work_date' => $day->toDateString(),
        ]);

        $this->actingAs($this->approver())
            ->post('/approvals/on-behalf/lateness', [
                'staff_id' => $staff->id,
                'work_date' => $day->toDateString(),
                'reason' => 'Filing the same morning a second time over.',
            ])
            ->assertSessionHasErrors('work_date');
    }
}
