<?php

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStage;
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

    public function test_the_relief_officer_signs_off_before_the_approver_sees_it(): void
    {
        $supervisor = $this->approver();
        $relief = $this->staff();
        $leave = LeaveRequest::factory()
            ->chained($relief, $supervisor)
            ->create(['user_id' => $this->staff()->id]);

        // The supervisor cannot jump the queue.
        $this->actingAs($supervisor)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $this->assertSame(0, $leave->refresh()->approvals()->count());
        $this->assertSame(RequestStatus::Pending, $leave->status);

        $this->actingAs($relief)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved'])
            ->assertSessionHasNoErrors();

        $leave->refresh()->load('approvals');

        // Cover agreed, but the relief sign-off is not one of the approvals.
        $this->assertSame(RequestStatus::Pending, $leave->status);
        $this->assertTrue($leave->reliefAgreed());
        $this->assertSame(0, $leave->approvalsGiven());

        $this->actingAs($supervisor)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $leave->refresh()->load('approvals');

        $this->assertSame(RequestStatus::Approved, $leave->status);
        $this->assertSame(1, $leave->approvalsGiven());
        $this->assertSame(
            ApprovalStage::Relief,
            $leave->approvals->firstWhere('approver_id', $relief->id)->stage,
        );
    }

    /**
     * Agreeing the cover can be the last thing waiting on a relief officer,
     * and the approvals page is not theirs once it is. Sending them "back"
     * to it would answer the one thing they were asked for with a 403.
     */
    public function test_a_relief_officer_is_sent_home_once_the_cover_is_agreed(): void
    {
        $relief = $this->staff();
        $leave = LeaveRequest::factory()
            ->chained($relief, $this->approver())
            ->create(['user_id' => $this->staff()->id]);

        $this->actingAs($relief)
            ->from('/approvals')
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved'])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('toast.type', 'success');
    }

    /**
     * An approver still has an inbox after deciding, so they stay on it.
     */
    public function test_an_approver_stays_on_the_inbox_after_deciding(): void
    {
        $leave = LeaveRequest::factory()->create(['user_id' => $this->staff()->id]);

        $this->actingAs($this->approver())
            ->from('/approvals')
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved'])
            ->assertRedirect('/approvals');
    }

    /**
     * The profile gate is about somebody using the app on a half-filled
     * record of their own. Answering what a colleague asked of you is not
     * that, and turning it away strands the colleague rather than the person
     * with the unfinished profile: their leave sits with cover that cannot
     * agree it, and they cannot hand it to anybody else.
     */
    public function test_an_unfinished_profile_does_not_stop_a_relief_officer_agreeing_cover(): void
    {
        $relief = User::factory()->withoutProfile()->create(['location_id' => $this->location->id]);
        $leave = LeaveRequest::factory()
            ->chained($relief, $this->approver())
            ->create(['user_id' => $this->staff()->id]);

        $this->actingAs($relief)->get('/approvals')->assertOk();

        $this->actingAs($relief)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($leave->refresh()->load('approvals')->reliefAgreed());
    }

    /**
     * The way out of the gate is only as wide as the reason for it. Filing a
     * request for somebody else is using the app for yourself, so it waits
     * for the profile like everything else does.
     */
    public function test_an_unfinished_profile_still_stops_an_approver_filing_for_someone(): void
    {
        $approver = User::factory()->approver()->withoutProfile()
            ->create(['location_id' => $this->location->id]);

        $this->actingAs($approver)
            ->post('/approvals/on-behalf/leave', ['staff_id' => $this->staff()->id])
            ->assertForbidden();
    }

    public function test_a_relief_officer_who_declines_sends_the_request_back(): void
    {
        $relief = $this->staff();
        $leave = LeaveRequest::factory()
            ->chained($relief, $this->approver())
            ->create(['user_id' => $this->staff()->id]);

        $this->actingAs($relief)
            ->post("/approvals/leave/{$leave->id}", [
                'decision' => 'rejected',
                'comment' => 'I am away that week myself.',
            ]);

        $this->assertSame(RequestStatus::Returned, $leave->refresh()->status);
        $this->assertNotNull($leave->decided_at);
    }

    public function test_only_the_named_approver_takes_the_first_approval(): void
    {
        $relief = $this->staff();
        $supervisor = $this->approver();
        $bystander = $this->approver();
        $leave = LeaveRequest::factory()
            ->chained($relief, $supervisor)
            ->create(['user_id' => $this->staff()->id, 'approvals_required' => 2]);

        $this->actingAs($relief)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $this->actingAs($bystander)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $this->assertSame(0, $leave->refresh()->load('approvals')->approvalsGiven());

        $this->actingAs($supervisor)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        // With two approvals asked for, anyone else may top it up afterwards.
        $this->actingAs($bystander)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);

        $this->assertSame(RequestStatus::Approved, $leave->refresh()->status);
    }

    public function test_a_relief_officer_without_approval_rights_reaches_the_inbox(): void
    {
        $relief = $this->staff();
        $leave = LeaveRequest::factory()
            ->chained($relief, $this->approver())
            ->create(['user_id' => $this->staff()->id]);

        $this->actingAs($relief)
            ->get('/approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Approvals')
                ->has('leave', 1)
                ->where('leave.0.stage', 'relief')
                ->has('lateness', 0));

        $this->actingAs($relief)
            ->post("/approvals/leave/{$leave->id}", ['decision' => 'approved'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($leave->refresh()->load('approvals')->reliefAgreed());

        // With the cover agreed they have no business on the page any more.
        $this->actingAs($relief)->get('/approvals')->assertForbidden();
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
