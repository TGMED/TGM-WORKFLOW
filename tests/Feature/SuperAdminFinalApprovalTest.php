<?php

namespace Tests\Feature;

use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Notifications\RequestDecided;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Leave runs: cover agrees, the manager approves, and a super admin gives the
 * final approval. Nobody is told their leave is approved before that.
 */
class SuperAdminFinalApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
    }

    private function user(string $state = 'staff'): User
    {
        $factory = User::factory();

        $factory = match ($state) {
            'approver' => $factory->approver(),
            'super' => $factory->superAdmin(),
            default => $factory,
        };

        return $factory->create(['location_id' => $this->location->id]);
    }

    private function approve(User $who, LeaveRequest $leave): void
    {
        $this->actingAs($who)->post("/approvals/leave/{$leave->id}", ['decision' => 'approved']);
    }

    /**
     * @return array<int, string>
     */
    private function subjectsSentTo(User $requester): array
    {
        return Notification::sent($requester, RequestDecided::class)
            ->map(fn (RequestDecided $n): string => $n->toMail($requester)->subject)
            ->values()
            ->all();
    }

    public function test_leave_runs_cover_then_manager_then_super_admin(): void
    {
        Notification::fake();

        $requester = $this->user();
        $relief = $this->user();
        $manager = $this->user('approver');
        $admin = $this->user('super');

        $leave = LeaveRequest::factory()->chained($relief, $manager)->create(['user_id' => $requester->id]);

        // The super admin cannot jump ahead of the cover or the manager.
        $this->approve($admin, $leave);
        $this->assertSame(0, $leave->refresh()->approvals()->count());

        $this->approve($relief, $leave);
        $this->approve($admin, $leave);
        $this->assertSame(1, $leave->refresh()->approvals()->count());

        $this->approve($manager, $leave);

        $leave->refresh()->load('approvals');
        $this->assertSame(RequestStatus::Pending, $leave->status);
        $this->assertTrue($leave->awaitsAdmin());
        $this->assertSame('With the Chairman for final approval', $leave->stageLabel());

        // It is now in the super admin's inbox, and they were told so.
        Notification::assertSentTo($admin, ApprovalRequested::class);
        $this->actingAs($admin)->get('/approvals')
            ->assertInertia(fn ($page) => $page->has('leave', 1));

        $this->approve($admin, $leave);

        $leave->refresh()->load('approvals');
        $this->assertSame(RequestStatus::Approved, $leave->status);
        $this->assertSame(
            ApprovalStage::Admin,
            $leave->approvals->firstWhere('approver_id', $admin->id)->stage,
        );
    }

    public function test_the_requester_is_not_told_approved_until_the_super_admin_approves(): void
    {
        Notification::fake();

        $requester = $this->user();
        $relief = $this->user();
        $manager = $this->user('approver');
        $admin = $this->user('super');

        $leave = LeaveRequest::factory()->chained($relief, $manager)->create(['user_id' => $requester->id]);

        $this->approve($relief, $leave);
        $this->approve($manager, $leave);

        $this->assertSame(
            ['Your cover has been agreed', 'Your request has moved forward'],
            $this->subjectsSentTo($requester),
        );

        $progress = Notification::sent($requester, RequestDecided::class)->last()->toMail($requester);
        $this->assertContains('The Chairman gives the final approval.', $progress->introLines);

        $this->approve($admin, $leave);

        $this->assertSame(
            ['Your cover has been agreed', 'Your request has moved forward', 'Your leave has been approved'],
            $this->subjectsSentTo($requester),
        );
    }

    public function test_an_ordinary_approver_cannot_give_the_final_approval(): void
    {
        $requester = $this->user();
        $relief = $this->user();
        $manager = $this->user('approver');
        $other = $this->user('approver');
        $this->user('super');

        $leave = LeaveRequest::factory()->chained($relief, $manager)->create(['user_id' => $requester->id]);

        $this->approve($relief, $leave);
        $this->approve($manager, $leave);
        $this->approve($other, $leave);

        $leave->refresh()->load('approvals');
        $this->assertSame(RequestStatus::Pending, $leave->status);
        $this->assertSame(1, $leave->approvalsGiven());
        $this->assertFalse($leave->awaitsDecisionFrom($other));
    }

    public function test_a_super_admin_named_as_the_approver_approves_in_one_step(): void
    {
        $requester = $this->user();
        $relief = $this->user();
        $admin = $this->user('super');

        $leave = LeaveRequest::factory()->chained($relief, $admin)->create(['user_id' => $requester->id]);

        $this->approve($relief, $leave);
        $this->approve($admin, $leave);

        $this->assertSame(RequestStatus::Approved, $leave->refresh()->status);
    }

    public function test_a_super_admin_who_declines_sends_the_leave_back(): void
    {
        $requester = $this->user();
        $relief = $this->user();
        $manager = $this->user('approver');
        $admin = $this->user('super');

        $leave = LeaveRequest::factory()->chained($relief, $manager)->create(['user_id' => $requester->id]);

        $this->approve($relief, $leave);
        $this->approve($manager, $leave);

        $this->actingAs($admin)->post("/approvals/leave/{$leave->id}", [
            'decision' => 'rejected',
            'comment' => 'Too many people are off that week.',
        ]);

        $this->assertSame(RequestStatus::Returned, $leave->refresh()->status);
    }

    public function test_approvers_show_by_job_title_and_the_final_approval_as_chairman_ceo(): void
    {
        Notification::fake();

        $requester = $this->user();
        $relief = User::factory()->create(['location_id' => $this->location->id, 'name' => 'Tolu Cover']);
        $manager = User::factory()->approver()->create([
            'location_id' => $this->location->id,
            'name' => 'Femi Ojo',
            'position' => 'Operations Manager',
        ]);
        $admin = User::factory()->superAdmin()->create([
            'location_id' => $this->location->id,
            'name' => 'Chidi Okafor',
        ]);

        $leave = LeaveRequest::factory()->chained($relief, $manager)->create(['user_id' => $requester->id]);

        $this->approve($relief, $leave);
        $this->assertSame('With the Operations Manager for approval', $leave->refresh()->load('approvals')->stageLabel());

        $this->approve($manager, $leave);
        $this->approve($admin, $leave);

        // The requester's own leave page: cover by name, approvers by office.
        $this->actingAs($requester)->get('/leave')
            ->assertInertia(fn ($page) => $page
                ->where('requests.0.supervisor', 'Operations Manager')
                ->where('requests.0.trail.0.approver', 'Tolu Cover')
                ->where('requests.0.trail.1.approver', 'Operations Manager')
                ->where('requests.0.trail.2.approver', 'Chairman')
                ->where('requests.0.trail.2.stage_label', 'Final approval'));

        // The approver's name never reaches the requester's emails.
        $mails = Notification::sent($requester, RequestDecided::class)
            ->map(fn (RequestDecided $n): string => implode(' ', $n->toMail($requester)->introLines));

        $this->assertStringContainsString('The Operations Manager has signed off', $mails[1]);
        $this->assertStringContainsString('The Chairman gave the final approval', $mails[2]);
        $this->assertStringNotContainsString('Femi Ojo', $mails->implode(' '));
        $this->assertStringNotContainsString('Chidi Okafor', $mails->implode(' '));
    }

    public function test_an_approver_with_no_job_title_shows_by_name(): void
    {
        $requester = $this->user();
        $relief = $this->user();
        $manager = User::factory()->approver()->create([
            'location_id' => $this->location->id,
            'name' => 'Femi Ojo',
            'position' => null,
        ]);
        $this->user('super');

        $leave = LeaveRequest::factory()->chained($relief, $manager)->create(['user_id' => $requester->id]);

        $this->approve($relief, $leave);
        $this->assertSame('With Femi Ojo for approval', $leave->refresh()->load('approvals')->stageLabel());

        $this->approve($manager, $leave);

        $this->actingAs($requester)->get('/leave')
            ->assertInertia(fn ($page) => $page
                ->where('requests.0.supervisor', 'Femi Ojo')
                ->where('requests.0.trail.1.approver', 'Femi Ojo'));
    }
}
