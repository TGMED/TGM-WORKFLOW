<?php

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\RequestStatus;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
    }

    private function approver(): User
    {
        return User::factory()->approver()->create(['location_id' => $this->location->id]);
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    public function test_an_approver_can_approve_a_leave_request(): void
    {
        $leave = LeaveRequest::factory()->create(['user_id' => $this->staff()->id]);

        $this->actingAs($this->approver())
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::Approved, $leave->refresh()->status);
        $this->assertNotNull($leave->decided_at);
        $this->assertSame(1, $leave->approvals()->count());
    }

    public function test_a_rejection_ends_the_request_whatever_is_outstanding(): void
    {
        $leave = LeaveRequest::factory()->create([
            'user_id' => $this->staff()->id,
            'approvals_required' => 3,
        ]);

        $this->actingAs($this->approver())
            ->post("/approvals/leave/{$leave->id}", [
                'decision' => 'rejected',
                'comment' => 'We are short-staffed that week.',
            ]);

        $this->assertSame(RequestStatus::Rejected, $leave->refresh()->status);
        $this->assertSame(
            'We are short-staffed that week.',
            $leave->approvals()->first()->comment,
        );
    }

    public function test_a_request_stays_pending_until_every_approval_is_in(): void
    {
        $leave = LeaveRequest::factory()->create([
            'user_id' => $this->staff()->id,
            'approvals_required' => 2,
        ]);

        $this->actingAs($this->approver())
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $this->assertSame(RequestStatus::Pending, $leave->refresh()->status);
        $this->assertSame(1, $leave->approvalsOutstanding());

        $this->actingAs($this->approver())
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $this->assertSame(RequestStatus::Approved, $leave->refresh()->status);
    }

    public function test_the_same_approver_cannot_decide_twice(): void
    {
        $approver = $this->approver();
        $leave = LeaveRequest::factory()->create([
            'user_id' => $this->staff()->id,
            'approvals_required' => 2,
        ]);

        $this->actingAs($approver)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);
        $this->actingAs($approver)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $this->assertSame(1, $leave->refresh()->approvals()->count());
        $this->assertSame(RequestStatus::Pending, $leave->status);
    }

    public function test_an_approver_cannot_decide_on_their_own_request(): void
    {
        $approver = $this->approver();
        $leave = LeaveRequest::factory()->create(['user_id' => $approver->id]);

        $this->actingAs($approver)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $this->assertSame(RequestStatus::Pending, $leave->refresh()->status);
        $this->assertSame(0, $leave->approvals()->count());
    }

    public function test_a_cancelled_request_can_no_longer_be_decided(): void
    {
        $leave = LeaveRequest::factory()->create([
            'user_id' => $this->staff()->id,
            'status' => RequestStatus::Cancelled,
        ]);

        $this->actingAs($this->approver())
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $this->assertSame(RequestStatus::Cancelled, $leave->refresh()->status);
        $this->assertSame(0, $leave->approvals()->count());
    }

    public function test_lateness_requests_run_through_the_same_ledger(): void
    {
        $late = LatenessRequest::factory()->create(['user_id' => $this->staff()->id]);

        $this->actingAs($this->approver())
            ->post("/approvals/lateness/{$late->id}", ['decision' => 'approved']);

        $late->refresh();

        $this->assertSame(RequestStatus::Approved, $late->status);
        $this->assertSame(
            ApprovalDecision::Approved,
            $late->approvals()->first()->decision,
        );
    }

    public function test_plain_staff_cannot_reach_the_inbox(): void
    {
        $this->actingAs($this->staff())
            ->get('/approvals')
            ->assertForbidden();
    }

    public function test_super_admins_can_stand_in_as_approvers(): void
    {
        $leave = LeaveRequest::factory()->create(['user_id' => $this->staff()->id]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $this->assertSame(RequestStatus::Approved, $leave->refresh()->status);
    }

    public function test_the_inbox_lists_what_is_waiting_on_this_approver(): void
    {
        $approver = $this->approver();
        $other = $this->approver();

        LeaveRequest::factory()->create(['user_id' => $this->staff()->id]);
        LatenessRequest::factory()->create(['user_id' => $this->staff()->id]);

        // Already decided by this approver, so it drops off their list.
        $decided = LeaveRequest::factory()->create([
            'user_id' => $this->staff()->id,
            'approvals_required' => 2,
        ]);
        $this->actingAs($approver)
            ->post("/approvals/leave/{$decided->id}", ['decision' => 'approved']);

        $this->actingAs($approver)
            ->get('/approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Approvals')
                ->has('leave', 1)
                ->has('lateness', 1)
                ->has('history', 1));

        // The other approver still sees the half-approved one.
        $this->actingAs($other)
            ->get('/approvals')
            ->assertInertia(fn ($page) => $page->has('leave', 2));
    }
}
