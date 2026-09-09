<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\RequestStatus;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A request goes to the people responsible for the requester before it goes to
 * anybody else: their team lead, then their head of department.
 */
class ReportingLineTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private Department $department;

    private Team $team;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
        $this->department = Department::factory()->create();
        $this->team = Team::factory()->create(['department_id' => $this->department->id]);
        $this->leaveType = LeaveType::factory()->create();
    }

    private function person(?Team $team = null): User
    {
        return User::factory()
            ->inDepartment($this->department, $team)
            ->create(['location_id' => $this->location->id]);
    }

    /**
     * Somebody in the line: they decide on requests, so they hold the
     * permission the approvals inbox reads.
     */
    private function approver(?Team $team = null): User
    {
        return User::factory()
            ->inDepartment($this->department, $team)
            ->roles(Role::STAFF, Role::APPROVER)
            ->create(['location_id' => $this->location->id]);
    }

    private function fileLeave(User $requester, User $cover, User $supervisor): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'user_id' => $requester->id,
            'leave_type_id' => $this->leaveType->id,
            'supervisor_id' => $supervisor->id,
            'relief_officer_id' => $cover->id,
            'start_date' => Carbon::now()->addWeek()->toDateString(),
            'end_date' => Carbon::now()->addWeek()->addDays(2)->toDateString(),
            'days' => 3,
            'status' => RequestStatus::Pending->value,
            'approvals_required' => 1,
        ]);
    }

    // Stamping.

    public function test_a_request_records_the_line_it_was_filed_under(): void
    {
        $lead = $this->approver($this->team);
        $head = $this->approver();

        $this->department->forceFill(['head_user_id' => $head->id])->save();
        $this->team->forceFill(['lead_user_id' => $lead->id])->save();

        $requester = $this->person($this->team);
        $leave = $this->fileLeave($requester, $this->person(), $this->approver());

        $this->assertSame($lead->id, $leave->team_lead_id);
        $this->assertSame($head->id, $leave->head_id);
    }

    public function test_a_reorganisation_does_not_move_a_request_already_filed(): void
    {
        $lead = $this->approver($this->team);
        $this->team->forceFill(['lead_user_id' => $lead->id])->save();

        $requester = $this->person($this->team);
        $leave = $this->fileLeave($requester, $this->person(), $this->approver());

        $replacement = $this->approver($this->team);
        $this->team->forceFill(['lead_user_id' => $replacement->id])->save();

        $this->assertSame($lead->id, $leave->fresh()->team_lead_id);
    }

    public function test_nobody_is_put_in_their_own_chain(): void
    {
        $lead = $this->approver($this->team);
        $head = $this->approver();

        $this->team->forceFill(['lead_user_id' => $lead->id])->save();
        $this->department->forceFill(['head_user_id' => $head->id])->save();

        // The lead's own request starts at their head, not at themselves.
        $leave = $this->fileLeave($lead, $this->person(), $this->approver());

        $this->assertNull($leave->team_lead_id);
        $this->assertSame($head->id, $leave->head_id);

        // The head's own request has no line at all.
        $theirs = $this->fileLeave($head, $this->person(), $this->approver());

        $this->assertNull($theirs->team_lead_id);
        $this->assertNull($theirs->head_id);
    }

    // The order of the run.

    public function test_the_lead_decides_before_the_head_and_the_head_before_the_supervisor(): void
    {
        $lead = $this->approver($this->team);
        $head = $this->approver();
        $supervisor = $this->approver();

        $this->team->forceFill(['lead_user_id' => $lead->id])->save();
        $this->department->forceFill(['head_user_id' => $head->id])->save();

        $requester = $this->person($this->team);
        $cover = $this->person($this->team);

        $leave = $this->fileLeave($requester, $cover, $supervisor);

        // The cover agrees first, as it always did.
        $this->decide($leave, $cover);

        $leave->refresh()->load('approvals');

        $this->assertTrue($leave->awaitsDecisionFrom($lead));
        $this->assertFalse($leave->awaitsDecisionFrom($head));
        $this->assertFalse($leave->awaitsDecisionFrom($supervisor));

        $this->decide($leave, $lead);
        $leave->refresh()->load('approvals');

        $this->assertTrue($leave->awaitsDecisionFrom($head));
        $this->assertFalse($leave->awaitsDecisionFrom($supervisor));

        $this->decide($leave, $head);
        $leave->refresh()->load('approvals');

        $this->assertTrue($leave->awaitsDecisionFrom($supervisor));
    }

    public function test_somebody_in_no_team_skips_the_lead_stage(): void
    {
        $head = $this->approver();
        $supervisor = $this->approver();

        $this->department->forceFill(['head_user_id' => $head->id])->save();

        // In the department, but in no team.
        $requester = $this->person();
        $cover = $this->person();

        $leave = $this->fileLeave($requester, $cover, $supervisor);

        $this->decide($leave, $cover);
        $leave->refresh()->load('approvals');

        $this->assertNull($leave->team_lead_id);
        $this->assertTrue($leave->awaitsDecisionFrom($head));
        $this->assertFalse($leave->awaitsDecisionFrom($supervisor));
    }

    public function test_a_request_with_no_line_at_all_behaves_as_it_always_did(): void
    {
        $supervisor = $this->approver();

        $loner = User::factory()->create(['location_id' => $this->location->id]);
        $cover = User::factory()->create(['location_id' => $this->location->id]);

        $leave = $this->fileLeave($loner, $cover, $supervisor);

        $this->decide($leave, $cover);
        $leave->refresh()->load('approvals');

        $this->assertNull($leave->team_lead_id);
        $this->assertNull($leave->head_id);
        $this->assertTrue($leave->awaitsDecisionFrom($supervisor));
    }

    // Cover comes from the requester's own department.

    public function test_the_relief_officer_list_is_the_requesters_own_department(): void
    {
        $requester = $this->person();
        $colleague = $this->person();
        $outsider = User::factory()
            ->inDepartment(Department::factory()->create())
            ->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($requester)->get('/leave');

        $officers = collect($response->viewData('page')['props']['relief_officers'])
            ->pluck('value');

        $this->assertTrue($officers->contains($colleague->id));
        $this->assertFalse($officers->contains($outsider->id));
        $this->assertFalse($officers->contains($requester->id));
    }

    public function test_cover_from_another_department_is_refused_on_save(): void
    {
        $requester = $this->person();
        $supervisor = $this->approver();

        $outsider = User::factory()
            ->inDepartment(Department::factory()->create())
            ->create(['location_id' => $this->location->id]);

        $this->actingAs($requester)
            ->post('/leave', [
                'leave_type_id' => $this->leaveType->id,
                'supervisor_id' => $supervisor->id,
                'relief_officer_id' => $outsider->id,
                'start_date' => Carbon::now()->addWeek()->toDateString(),
                'end_date' => Carbon::now()->addWeek()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('relief_officer_id');
    }

    public function test_somebody_in_no_department_still_gets_a_list_to_pick_from(): void
    {
        $loner = User::factory()->create(['location_id' => $this->location->id]);
        $this->person();

        $response = $this->actingAs($loner)->get('/leave');

        $this->assertNotEmpty($response->viewData('page')['props']['relief_officers']);
    }

    /**
     * Record a decision the way the approvals page would.
     */
    private function decide(LeaveRequest $leave, User $approver): void
    {
        $this->actingAs($approver)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved'])
            ->assertSessionHasNoErrors();
    }
}
