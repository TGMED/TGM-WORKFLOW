<?php

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\NotificationTopic;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Notifications\RequestDecided;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Who hears about a request, and when.
 */
class RequestNotificationTest extends TestCase
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

    private function annual(): LeaveType
    {
        return LeaveType::query()->where('slug', 'annual')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $supervisor, User $relief): array
    {
        $monday = Carbon::now()->addWeek()->startOfWeek();

        return [
            'leave_type_id' => $this->annual()->id,
            'supervisor_id' => $supervisor->id,
            'relief_officer_id' => $relief->id,
            'start_date' => $monday->toDateString(),
            'end_date' => $monday->copy()->addDays(2)->toDateString(),
            'reason' => 'Family wedding upcountry.',
        ];
    }

    public function test_the_relief_officer_is_told_when_leave_is_raised(): void
    {
        Notification::fake();

        $staff = $this->staff();
        $relief = $this->staff();
        $supervisor = User::factory()->approver()->create();

        $this->actingAs($staff)
            ->post('/leave', $this->payload($supervisor, $relief))
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($relief, ApprovalRequested::class);

        // The approver's turn comes only once cover is agreed, so they are not
        // written to yet.
        Notification::assertNotSentTo($supervisor, ApprovalRequested::class);
    }

    public function test_the_approver_is_told_once_cover_is_agreed(): void
    {
        $staff = $this->staff();
        $relief = $this->staff();
        $supervisor = User::factory()->approver()->create();

        $this->actingAs($staff)->post('/leave', $this->payload($supervisor, $relief));

        $leave = LeaveRequest::query()->firstOrFail();

        Notification::fake();

        app(ApprovalService::class)->decide($leave, $relief, ApprovalDecision::Approved);

        Notification::assertSentTo($supervisor, ApprovalRequested::class);
        // And the person who asked hears that their cover is sorted.
        Notification::assertSentTo($staff, RequestDecided::class);
    }

    public function test_lateness_reaches_the_approvers(): void
    {
        Notification::fake();

        $staff = $this->staff();
        $approver = User::factory()->approver()->create();

        $this->actingAs($staff)
            ->post('/lateness', [
                'work_date' => Carbon::now()->toDateString(),
                'reason' => 'The bridge was closed for repairs this morning.',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($approver, ApprovalRequested::class);
    }

    public function test_the_approver_who_filed_for_someone_hears_the_outcome_too(): void
    {
        $staff = $this->staff();
        $filer = User::factory()->approver()->create(['location_id' => $this->location->id]);
        $supervisor = User::factory()->approver()->create();
        $relief = $this->staff();

        $this->actingAs($filer)->post('/approvals/on-behalf/leave', [
            ...$this->payload($supervisor, $relief),
            'staff_id' => $staff->id,
        ])->assertSessionHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();

        Notification::fake();

        app(ApprovalService::class)->decide($leave, $relief, ApprovalDecision::Approved);

        Notification::assertSentTo($staff, RequestDecided::class);
        Notification::assertSentTo($filer, RequestDecided::class);
    }

    public function test_switching_off_decisions_stops_the_email_but_not_the_approval_ask(): void
    {
        $staff = $this->staff();
        $relief = $this->staff();
        $supervisor = User::factory()->approver()->create();

        // The requester wants nothing about decisions; the relief officer has
        // tried to switch off approvals, which is not theirs to switch off.
        NotificationSetting::query()->create([
            'user_id' => $staff->id,
            'topic' => NotificationTopic::RequestDecided->value,
            'email' => false,
            'push' => false,
        ]);

        NotificationSetting::query()->create([
            'user_id' => $relief->id,
            'topic' => NotificationTopic::ApprovalRequested->value,
            'email' => false,
            'push' => false,
        ]);

        $this->actingAs($staff)->post('/leave', $this->payload($supervisor, $relief));

        $leave = LeaveRequest::query()->firstOrFail();

        Notification::fake();

        app(ApprovalService::class)->decide($leave, $relief, ApprovalDecision::Approved);

        // With every channel switched off there is nothing to send, so the
        // notification never leaves.
        Notification::assertNotSentTo($staff, RequestDecided::class);

        Notification::assertSentTo(
            $supervisor,
            ApprovalRequested::class,
            fn (ApprovalRequested $notification, array $channels): bool => in_array('mail', $channels, true),
        );
    }

    public function test_a_deactivated_person_is_not_written_to(): void
    {
        $staff = $this->staff();
        $relief = $this->staff();
        $supervisor = User::factory()->approver()->create();

        $this->actingAs($staff)->post('/leave', $this->payload($supervisor, $relief));

        $leave = LeaveRequest::query()->firstOrFail();

        $staff->forceFill(['is_active' => false])->save();

        Notification::fake();

        app(ApprovalService::class)->decide($leave->fresh(), $relief, ApprovalDecision::Approved);

        Notification::assertNotSentTo($staff, RequestDecided::class);
    }
}
